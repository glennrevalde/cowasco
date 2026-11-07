<?php
declare(strict_types=1);

/**
 * End-to-end acceptance tests of the water module, driven over HTTP exactly as the browser drives it (login, CSRF,
 * the JSON and form endpoints of the water pages, the accounting pages), with the results checked in the database.
 *
 *   DB_NAME=<db> php tests/water/e2e_http.php --base=http://127.0.0.1:8091 [--out=tests/water/RESULTS_E2E.md]
 *
 * The web server must serve this folder with the same DB_NAME. The database must be a fresh install with the former
 * water database migrated into it (tests/water/run_e2e.sh does all of that). Section numbers follow section 7 of the
 * implementation prompt.
 */
if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}
$opts = getopt('', ['base:', 'out:']);
$base = (string)($opts['base'] ?? 'http://127.0.0.1:8091');
require __DIR__ . '/../../app/bootstrap.php';
require __DIR__ . '/e2e_lib.php';
// the water helpers, to compute what a bill must come to independently of the pages (same functions the pages use)
require __DIR__ . '/../../water/database/config.php';
require_once __DIR__ . '/../../water/common/billingHelpers.php';
require_once __DIR__ . '/../../water/common/waterRateBillingHelpers.php';
$_SESSION = [];
Tenant::bootCli();
$adminId = (int)DB::value("SELECT id FROM users WHERE username = 'admin'");
Auth::actAs($adminId);
$started = microtime(true);
$today = date('Y-m-d');
$PASS1 = 'Admin@12345';
$PASS2 = 'Water#Test2026';

$q = fn(string $sql, array $p = []) => DB::value($sql, $p);
$row = fn(string $sql, array $p = []) => DB::row($sql, $p);
$money = fn($v) => round((float)$v, 2);
$tbDiff = fn() => $money(DB::value('SELECT COALESCE(SUM(debit - credit), 0) FROM journal_lines'));
$txnBalanced = fn(int $id) => abs((float)DB::value('SELECT COALESCE(SUM(debit - credit), 0) FROM journal_lines WHERE txn_id = :t', ['t' => $id])) < 0.005;
$openOf = fn(int $txnId) => Balances::open(Txn::load($txnId));

// ======================================================================================== 0. set-up
Checks::section('0. Set-up: one login, one session, mapping checks before posting');
$admin = new Browser($base, 'admin');
Checks::true('admin signs in on the one login page (and sets a new password)', $admin->login('admin', $PASS1, $PASS2) || $admin->login('admin', $PASS2), $admin->url);
$admin->get('water/views/dashboard.php');
Checks::eq('a water page opens in the same session (no second login)', 200, $admin->status);
Checks::true('the water page shows the unified menu (Water Billing, Chart of Accounts, Customers)', str_contains($admin->body, 'wsh-sidebar') && str_contains($admin->body, 'Chart of Accounts') && str_contains($admin->body, 'Water Billing'));
Checks::true('no PHP error on the water dashboard', !preg_match('/(Fatal error|Warning:|Notice:|Deprecated:)/', $admin->body));

// the cashier's cash account and the collection policy (what an administrator sets once before go-live)
$cash = (int)$q("SELECT id FROM accounts WHERE code = '11118'");               // Cash in Vault, from the migrated chart
DB::run('UPDATE users SET coop_cash_account_id = :c WHERE id = :u', ['c' => $cash, 'u' => $adminId]);
$penaltyIncome = (int)$q("SELECT id FROM accounts WHERE category = 'Income' AND is_active = 1 AND name LIKE '%Penalt%' ORDER BY code LIMIT 1") ?: (int)$q("SELECT id FROM accounts WHERE code = '40450'");
$compromiseIncome = (int)$q("SELECT id FROM accounts WHERE code = '40450'");
$admin->post('water/process/saveCollectionPolicyConfiguration.php', ['csrf_token' => $admin->csrf(), 'collection_penalty_account_id' => $penaltyIncome,
    'collection_compromise_account_id' => $compromiseIncome, 'manual_penalty_enabled' => 1, 'manual_compromise_enabled' => 1, 'session_inactivity_hours' => 24]);
$policyMsg = preg_match('/class="[^"]*(?:alert|flash)[^"]*"[^>]*>(.*?)</s', $admin->body, $mm) ? trim(strip_tags($mm[1])) : '';
Checks::eq('collection policy saved through its page (penalty income account)', $penaltyIncome, (int)$q("SELECT setting_value FROM water_billing_settings WHERE setting_key = 'collection_penalty_account_id'"));
if ((int)$q("SELECT setting_value FROM water_billing_settings WHERE setting_key = 'collection_penalty_account_id'") !== $penaltyIncome) {
    echo "     page said: " . substr(preg_replace('/\s+/', ' ', strip_tags($admin->body)), 0, 600) . "\n";
}
// the institution identity and the report signatories: the water program refuses to bill until they are saved
$admin->post('water/process/saveInstitutionConfiguration.php', ['csrf_token' => $admin->csrf(), 'officers' => ['manager' => 'Maria Santos', 'general_manager' => 'Jose Reyes']]);
Checks::eq('report signatories saved through Institution Configuration', 2, (int)$q("SELECT COUNT(*) FROM companyofficers WHERE COALESCE(isdeleted, 0) = 0 AND name IN ('Maria Santos', 'Jose Reyes')"));

// the accounts used: active, with a rate code whose tariff computes
$accts = [];
foreach (DB::column("SELECT pkey FROM water_accounts WHERE status = 'A' AND connection_status = 'active' AND rc_id IS NOT NULL ORDER BY pkey LIMIT 400") as $id) {
    try {
        $d = compute_water_billing_details(DB::pdo(), (int)$id, 23, true);
        if ((float)$d['amount_due'] > 0) {
            $accts[] = (int)$id;
        }
    } catch (Throwable $e) {
    }
    if (count($accts) >= 6) {
        break;
    }
}
Checks::eq('six active water-service accounts with a working tariff found', 6, count($accts));
[$A, $B, $C, $D, $E, $F] = $accts;
$period = $row("SELECT * FROM bill_periods WHERE status = 'open' ORDER BY period_id DESC LIMIT 1");
Checks::true('an open billing period that has ended exists (' . ($period['bp_code'] ?? '-') . ')', $period && $period['end_date'] < date('Y-m-d H:i:s'));
$pid = (int)$period['period_id'];
// every water transaction asks for its class (Chart of Accounts > Class List, the classes of the accounting books):
// an administrator sets the classes once before go-live
$classId = (int)$q('SELECT id FROM classes WHERE is_active = 1 ORDER BY id LIMIT 1')
    ?: (int)DB::insert('classes', ['name' => 'Water Utility', 'description' => 'Water service (e2e)', 'sort_order' => 10, 'created_by' => $adminId]);
Classes::reset();
$org = ['class_id' => $classId];

// ======================================================================================== 7.1 reading -> bill -> A/R -> payment -> OR -> GL -> statement -> reports
Checks::section('7.1 Meter reading, rate computation, bill, A/R, collection, official receipt, GL, party statement, reports');
$readings = [];
foreach ([$A => 23, $B => 8, $C => 41, $D => 15, $E => 30, $F => 12] as $acct => $cons) {
    $expected = compute_water_billing_details(DB::pdo(), $acct, $cons, true);
    $admin->postJson('water/process/saveIndividualReading.php', ['period_id' => $pid, 'member_id' => $acct, 'pres_reading' => $cons]);
    $ok = ($admin->json['success'] ?? false) === true;
    Checks::true("reading saved for account $acct ($cons m3)", $ok, $admin->json['message'] ?? $admin->json['error'] ?? substr($admin->body, 0, 200));
    if ($ok) {
        $readings[$acct] = ['id' => (int)$admin->json['reading_id'], 'cons' => $cons, 'due' => $money($admin->json['amount_due'])];
        Checks::eq("account $acct: amount computed by the page = the tariff (same formula as before)", $money($expected['amount_due']), $money($admin->json['amount_due']));
    }
}
$draftTxns = (int)$q('SELECT COUNT(*) FROM transactions');
Checks::eq('draft readings post nothing to the ledger', 0, (int)$q("SELECT COUNT(*) FROM water_postings WHERE source_type = 'water_bill'"));

$admin->postJson('water/process/postBilling.php', ['action' => 'post', 'period_id' => $pid, 'filter' => 'water', 'billing_date' => $today, 'term_days' => 15]);
Checks::true('Post Billing without a class is refused (the class is required)', ($admin->json['success'] ?? true) === false && stripos((string)($admin->json['error'] ?? $admin->json['message'] ?? ''), 'class') !== false, $admin->json['error'] ?? substr($admin->body, 0, 200));
Checks::eq('...and posts nothing', 0, (int)$q("SELECT COUNT(*) FROM water_postings WHERE source_type = 'water_bill'"));
$admin->postJson('water/process/postBilling.php', ['action' => 'post', 'period_id' => $pid, 'filter' => 'water', 'billing_date' => $today, 'term_days' => 15] + $org);
Checks::true('Post Billing posts the period', ($admin->json['success'] ?? false) === true, $admin->json['error'] ?? substr($admin->body, 0, 300));
Checks::eq('every ledger line of the water bills carries the class chosen on the posting', 0, (int)$q("SELECT COUNT(*) FROM water_postings w JOIN journal_lines l ON l.txn_id = w.txn_id WHERE w.source_type = 'water_bill' AND COALESCE(l.class_id, 0) <> :c", ['c' => $classId]));
Checks::eq('the posted bills keep their class', 0, (int)$q('SELECT COUNT(*) FROM bill_readings WHERE is_billed = 1 AND period_id = :p AND COALESCE(class_id, 0) <> :c', ['p' => $pid, 'c' => $classId]));
Checks::eq('bills posted', count($readings), (int)($admin->json['posted_count'] ?? 0));
$inv = [];
foreach ($readings as $acct => $r) {
    $w = WaterPosting::byEvent('WBILL-' . $r['id']);
    Checks::true("bill of account $acct has its accounting document (event WBILL-{$r['id']})", $w !== null && $w['status'] === 'posted');
    if (!$w) {
        continue;
    }
    $t = Txn::load((int)$w['txn_id']);
    $inv[$acct] = (int)$t['id'];
    $br = $row('SELECT * FROM bill_readings WHERE reading_id = :r', ['r' => $r['id']]);
    Checks::eq("account $acct: invoice total = water bill amount", $money($br['amount_due']), $money($t['total']));
    Checks::eq("account $acct: invoice number = the bill's own reference number", (string)$br['global_ref_number'], (string)$t['txn_no']);
    Checks::eq("account $acct: invoice is A/R of the account holder's customer record", (int)$q('SELECT customer_id FROM water_accounts WHERE pkey = :a', ['a' => $acct]), (int)$t['customer_id']);
    Checks::eq("account $acct: customer record belongs to the account's party", (int)$q('SELECT party_id FROM water_accounts WHERE pkey = :a', ['a' => $acct]), (int)$q('SELECT party_id FROM customers WHERE id = :c', ['c' => $t['customer_id']]));
    Checks::true("account $acct: invoice balanced (debits = credits)", $txnBalanced((int)$t['id']));
    Checks::eq("account $acct: invoice carries the Water Utility line of business", (int)WaterPosting::unitId(), (int)$t['business_unit_id']);
    Checks::eq("account $acct: A/R debit on the water A/R account", 'Accounts Receivable', (string)$q('SELECT a.account_type FROM journal_lines j JOIN accounts a ON a.id = j.account_id WHERE j.txn_id = :t AND j.debit > 0 LIMIT 1', ['t' => $t['id']]));
}
$admin->postJson('water/process/postBilling.php', ['action' => 'post', 'period_id' => $pid, 'filter' => 'water', 'billing_date' => $today, 'term_days' => 15] + $org);
Checks::true('posting the same period again is refused', ($admin->json['success'] ?? true) === false, $admin->json['error'] ?? '');
Checks::eq('...and creates no second invoice', count($readings), (int)$q("SELECT COUNT(*) FROM water_postings WHERE source_type = 'water_bill'"));

// partial payment of A, then the rest; official receipts
$dueA = $readings[$A]['due'];
$half = $money(floor($dueA * 50) / 100);
$key1 = 'e2e' . bin2hex(random_bytes(10));
$pay = ['member_id' => $A, 'items' => [['type' => 'water', 'id' => $readings[$A]['id'], 'payment_amount' => $half]], 'tendered' => $half, 'transaction_date' => $today, 'idempotency_key' => $key1] + $org;
$noClass = $pay;
unset($noClass['class_id']);
$noClass['idempotency_key'] = 'e2e' . bin2hex(random_bytes(10));
$admin->postJson('water/process/processUnifiedPayment.php', $noClass);
Checks::true('a collection without a class is refused', ($admin->json['success'] ?? true) === false, substr($admin->body, 0, 200));
$admin->postJson('water/process/processUnifiedPayment.php', $pay);
$r1 = (string)($admin->json['receipt_no'] ?? '');
Checks::eq('the receipt and its journal entry carry the class', [$classId, $classId], [(int)$q('SELECT class_id FROM payment_transactions WHERE receipt_no = :r', ['r' => $r1]),
    (int)$q("SELECT t.class_id FROM water_postings w JOIN transactions t ON t.id = w.txn_id WHERE w.source_type = 'collection' AND w.doc_no = :r LIMIT 1", ['r' => $r1])]);
Checks::true('partial collection of account A accepted, receipt issued', ($admin->json['success'] ?? false) === true && $r1 !== '', $admin->json['error'] ?? substr($admin->body, 0, 200));
$p1 = WaterPosting::byEvent(WaterPosting::collectionKey($r1));
Checks::true('the receipt has one payment document in the ledger', $p1 !== null);
$pt1 = $p1 ? Txn::load((int)$p1['txn_id']) : null;
Checks::eq('payment number = official receipt number', $r1, (string)($pt1['txn_no'] ?? ''));
Checks::eq('payment amount', $half, $money($pt1['total'] ?? 0));
Checks::eq('payment debits the cashier\'s cash account', $cash, (int)$q('SELECT account_id FROM journal_lines WHERE txn_id = :t AND debit > 0', ['t' => $pt1['id'] ?? 0]));
Checks::eq('invoice A open balance after the partial payment', $money($dueA - $half), $openOf($inv[$A]));
Checks::eq('water bill A amount_paid', $half, $money($q('SELECT amount_paid FROM bill_readings WHERE reading_id = :r', ['r' => $readings[$A]['id']])));
Checks::true('receipt document balanced', $pt1 && $txnBalanced((int)$pt1['id']));

// the same request again (a retry after a network failure): the same receipt, nothing posted twice
$beforePay = (int)$q("SELECT COUNT(*) FROM transactions WHERE txn_type = 'payment'");
$admin->postJson('water/process/processUnifiedPayment.php', $pay);
Checks::eq('retry of the same request returns the same receipt', $r1, (string)($admin->json['receipt_no'] ?? ''));
Checks::true('retry is reported as a duplicate', ($admin->json['duplicate'] ?? false) === true);
Checks::eq('retry posts no second payment', $beforePay, (int)$q("SELECT COUNT(*) FROM transactions WHERE txn_type = 'payment'"));

// two cashiers pay the rest of A at the same moment: one succeeds, the other is refused, A/R is never over-applied
$cashier2 = new Browser($base, 'admin2');
$cashier2->login('admin', $PASS2);
$rest = $money($dueA - $half);
$job = fn(Browser $b) => [$b, $b->handle('POST', 'water/process/processUnifiedPayment.php', ['member_id' => $A, 'items' => [['type' => 'water', 'id' => $readings[$A]['id'], 'payment_amount' => $rest]], 'tendered' => $rest,
    'transaction_date' => $today, 'idempotency_key' => 'e2e' . bin2hex(random_bytes(10)), 'csrf_token' => $b->csrf()] + $org, true, ['X-CSRF-Token: ' . $b->csrf()])];
$res = Browser::parallel([$job($admin), $job($cashier2)]);
$okCount = count(array_filter($res, fn($b) => ($b->json['success'] ?? false) === true));
Checks::eq('two simultaneous collections of the same balance: exactly one accepted', 1, $okCount, $okCount === 1);
Checks::eq('invoice A fully settled, not over-applied', 0.0, $openOf($inv[$A]));
Checks::eq('water bill A is paid in full', $dueA, $money($q('SELECT amount_paid FROM bill_readings WHERE reading_id = :r', ['r' => $readings[$A]['id']])));
$rA2 = '';
foreach ($res as $b) {
    if (($b->json['success'] ?? false) === true) {
        $rA2 = (string)$b->json['receipt_no'];
    }
}

// several bills in one receipt, with a penalty the cashier enters
$items = [['type' => 'water', 'id' => $readings[$B]['id']], ['type' => 'water', 'id' => $readings[$C]['id']]];
$sameHolder = (int)$q('SELECT party_id FROM water_accounts WHERE pkey = :b', ['b' => $B]) === (int)$q('SELECT party_id FROM water_accounts WHERE pkey = :c', ['c' => $C]);
$admin->postJson('water/process/processUnifiedPayment.php', ['member_id' => $B, 'items' => [['type' => 'water', 'id' => $readings[$B]['id']]], 'tendered' => $readings[$B]['due'] + 25,
    'transaction_date' => $today, 'idempotency_key' => 'e2e' . bin2hex(random_bytes(10)), 'apply_penalty' => 1, 'penalty_amount' => 25] + $org);
$rB = (string)($admin->json['receipt_no'] ?? '');
Checks::true('collection of account B with a 25.00 penalty accepted', ($admin->json['success'] ?? false) === true, $admin->json['error'] ?? substr($admin->body, 0, 200));
$pen = $rB !== '' ? WaterPosting::byEvent(WaterPosting::collectionKey($rB) . '#PEN') : null;
Checks::true('the penalty is a finance charge of the account, settled by the same receipt', $pen !== null && $openOf((int)$pen['txn_id']) < 0.005, $pen ? 'open ' . $openOf((int)$pen['txn_id']) : 'no penalty document (penalty field name?)');
if ($pen) {
    Checks::eq('penalty credited to the penalty income account', $penaltyIncome, (int)$q('SELECT account_id FROM journal_lines WHERE txn_id = :t AND credit > 0', ['t' => $pen['txn_id']]));
}

// GL, subledger and the party statement agree
Checks::eq('trial balance: debits = credits', 0.0, $tbDiff());
foreach ([$A, $B, $C] as $acct) {
    $ob = WaterAccounts::openBalance($acct);
    Checks::eq("account $acct: water open balance = ledger open balance", $money($ob['total']), $money(WaterAccounts::ledgerBalance($acct)));
}
$partyA = (int)$q('SELECT party_id FROM water_accounts WHERE pkey = :a', ['a' => $A]);
$admin->get('index.php?p=party.statement&party=' . $partyA . '&from=' . date('Y-01-01') . '&to=' . $today);
Checks::eq('Consolidated Party Statement of account A opens', 200, $admin->status);
Checks::true('...lists the water invoice number', str_contains($admin->body, (string)$q('SELECT txn_no FROM transactions WHERE id = :t', ['t' => $inv[$A]])));
Checks::true('...lists the official receipt number', $r1 !== '' && str_contains($admin->body, $r1));
$admin->get('water/views/generate_official_receipt.php?receipt_no=' . rawurlencode($r1));
$issuer = mb_strtoupper(Coop::coopName());
Checks::true('the printable official receipt opens with its number', $admin->status === 200 && str_contains($admin->body, $r1), (string)$admin->status);
Checks::true('...issued in the cooperative\'s own name (company settings), not a hard-coded one', $issuer !== '' && str_contains($admin->body, htmlspecialchars($issuer, ENT_QUOTES)) && !str_contains($admin->body, 'COWASCO'), $issuer);
$admin->get('index.php?p=party.center&id=' . $partyA);
Checks::true('Party Center shows the water-service account of the party', str_contains($admin->body, 'Water service') && str_contains($admin->body, (string)$q('SELECT account_number FROM water_accounts WHERE pkey = :a', ['a' => $A])));
foreach (['rpt.trial_balance' => 'Trial Balance', 'rpt.general_ledger' => 'General Ledger', 'rpt.balance_sheet' => 'Balance Sheet', 'rpt.income_statement' => 'Income Statement',
          'rpt.cash_flow' => 'Cash Flows', 'rpt.ar_aging_summary' => 'A/R Aging', 'rpt.water_collection_summary' => 'Collection Summary', 'rpt.water_revenue_period' => 'Revenue by Billing Period',
          'rpt.water_receivables_source' => 'Receivables by Source', 'rpt.water_payment_history' => 'Payment History', 'rpt.water_account_statement' => 'Water Service Account Statement',
          'rpt.water_posting_log' => 'Water Posting Log', 'rpt.water_mapping_check' => 'Account Mapping Validation', 'rpt.ap_aging_summary' => 'A/P Aging', 'admin.audit_trail' => 'Audit Trail'] as $key => $label) {
    $admin->get('index.php?p=' . $key . '&from=' . date('Y-01-01') . '&to=' . $today . '&dates=custom');
    Checks::true("report $label opens without an error", $admin->status === 200 && !preg_match('/(Fatal error|Warning:|Notice:|Something went wrong)/', $admin->body), 'status ' . $admin->status);
}
$admin->get('index.php?p=rpt.water_collection_summary&from=' . $today . '&to=' . $today . '&dates=custom');
Checks::true('Collection Summary shows today\'s receipt total', str_contains($admin->body, number_format($half, 2)) || str_contains($admin->body, number_format($dueA, 2)));

// ======================================================================================== 7.4 voids, reversals, corrections
Checks::section('7.4 Corrections: bill edit before collection, refusal after, collection reversal, re-collection');
// a bill not collected yet (D) is corrected: the invoice is voided and a revision posted, both kept and linked
$oldD = $readings[$D];
$admin->post('water/process/billLifecycleProcess.php', ['csrf_token' => $admin->csrf(), 'action' => 'edit', 'bill_type' => 'water', 'bill_id' => $oldD['id'], 'pres_reading' => 19, 'reason' => 'Meter misread (e2e test)'] + $org);
$wOrig = WaterPosting::byEvent('WBILL-' . $oldD['id']);
$wRev = WaterPosting::byEvent('WBILL-' . $oldD['id'] . '-R1');
Checks::true('the original invoice is voided (kept, marked void)', $wOrig && $wOrig['status'] !== 'posted' && (int)$q('SELECT is_voided FROM transactions WHERE id = :t', ['t' => $wOrig['txn_id']]) === 1, $wOrig ? $wOrig['status'] : 'missing');
Checks::true('a revision invoice "-R1" is posted and linked to the original', $wRev && $wRev['status'] === 'posted' && (int)$wRev['reversal_of_id'] === (int)($wOrig['id'] ?? -1), $wRev ? $wRev['doc_no'] : 'missing');
if ($wRev) {
    $newDue = $money(compute_water_billing_details(DB::pdo(), $D, 19, true)['amount_due']);
    Checks::eq('revision total = the bill recomputed for 19 m3', $newDue, $money($q('SELECT total FROM transactions WHERE id = :t', ['t' => $wRev['txn_id']])));
    Checks::true('revision number shows the invoice number with -R1', str_ends_with((string)$q('SELECT txn_no FROM transactions WHERE id = :t', ['t' => $wRev['txn_id']]), '-R1'));
}
// a collected bill (A) cannot be edited
$admin->post('water/process/billLifecycleProcess.php', ['csrf_token' => $admin->csrf(), 'action' => 'edit', 'bill_type' => 'water', 'bill_id' => $readings[$A]['id'], 'pres_reading' => 30, 'reason' => 'Should be refused'] + $org);
Checks::true('editing a collected bill is refused (no new revision)', WaterPosting::byEvent('WBILL-' . $readings[$A]['id'] . '-R1') === null);
// the first receipt of A is reversed: its payment voided, the bill reopened by that amount, then collected again
$admin->post('water/process/collectionCorrectionProcess.php', ['csrf_token' => $admin->csrf(), 'receipt_no' => $r1, 'action' => 'error_correction', 'business_date' => $today, 'reason' => 'Wrong amount keyed (e2e test)'] + $org);
$p1 = WaterPosting::byEvent(WaterPosting::collectionKey($r1));
Checks::true('reversed receipt: its payment document is voided, not deleted', $p1 && $p1['status'] === 'voided' && (int)$q('SELECT is_voided FROM transactions WHERE id = :t', ['t' => $p1['txn_id']]) === 1, $p1['status'] ?? 'missing');
Checks::true('the reversal is recorded with its reason and links to the receipt', (bool)$q("SELECT id FROM water_postings WHERE event_key = :k AND reversal_of_id = :o", ['k' => 'REV-' . WaterPosting::collectionKey($r1), 'o' => $p1['id'] ?? 0]));
Checks::eq('invoice A open again by the reversed amount', $half, $openOf($inv[$A]));
Checks::eq('water bill A amount_paid restored', $money($dueA - $half), $money($q('SELECT amount_paid FROM bill_readings WHERE reading_id = :r', ['r' => $readings[$A]['id']])));
$admin->post('water/process/collectionCorrectionProcess.php', ['csrf_token' => $admin->csrf(), 'receipt_no' => $r1, 'action' => 'error_correction', 'business_date' => $today, 'reason' => 'Twice'] + $org);
Checks::eq('reversing the same receipt twice changes nothing', $half, $openOf($inv[$A]));
$admin->postJson('water/process/processUnifiedPayment.php', ['member_id' => $A, 'items' => [['type' => 'water', 'id' => $readings[$A]['id']]], 'tendered' => $half, 'transaction_date' => $today, 'idempotency_key' => 'e2e' . bin2hex(random_bytes(10))] + $org);
Checks::true('the corrected collection is made with a new receipt number', ($admin->json['success'] ?? false) === true && ($admin->json['receipt_no'] ?? '') !== $r1, $admin->json['error'] ?? '');
Checks::eq('invoice A settled again', 0.0, $openOf($inv[$A]));
Checks::eq('trial balance after corrections: debits = credits', 0.0, $tbDiff());

// ======================================================================================== other bill sources and mapping exceptions
Checks::section('7.1b One-time, installment and recurring bills; mapping exceptions block posting');
$memCode = (int)$q("SELECT code_id FROM bill_codes WHERE code = 'MEMBERSHIP FEE'");
$beforeOtb = (int)$q("SELECT COUNT(*) FROM water_postings WHERE source_type = 'one_time_bill'");
$admin->post('water/process/addOneTimeBill.php', ['csrf_token' => $admin->csrf(), 'member_id' => $E, 'bill_code_id' => $memCode, 'quantity' => 1, 'total_amount' => 50, 'bill_date' => $today, 'due_date' => $today] + $org);
Checks::eq('a bill code whose A/R account is not a receivable cannot post (legacy set-up exception)', $beforeOtb, (int)$q("SELECT COUNT(*) FROM water_postings WHERE source_type = 'one_time_bill'"));
Checks::eq('...and no one-time bill is left half-saved', 0, (int)$q('SELECT COUNT(*) FROM one_time_bills WHERE member_id = :m', ['m' => $E]));
// the administrator corrects the bill code on its page, then the bill posts
$waterAr = (int)$q("SELECT id FROM accounts WHERE code = '11784'");
$serviceFee = (int)$q("SELECT id FROM accounts WHERE code = '40128'");
$bc = $row('SELECT * FROM bill_codes WHERE code_id = :c', ['c' => $memCode]);
$admin->post('water/process/editBillCode.php', ['csrf_token' => $admin->csrf(), 'code_id' => $memCode, 'code' => $bc['code'], 'description' => $bc['description'], 'type' => $bc['type'],
    'ar_account' => $waterAr, 'income_account' => $serviceFee, 'cost_price' => $bc['cost_price'], 'sales_price' => $bc['sales_price'], 'allow_member_discount' => $bc['allow_member_discount']]);
Checks::eq('bill code corrected on its page (A/R = Accounts Receivable - Water)', $waterAr, (int)$q('SELECT ar_account FROM bill_codes WHERE code_id = :c', ['c' => $memCode]));
$admin->post('water/process/addOneTimeBill.php', ['csrf_token' => $admin->csrf(), 'member_id' => $E, 'bill_code_id' => $memCode, 'quantity' => 1, 'total_amount' => 50, 'bill_date' => $today, 'due_date' => $today] + $org);
$otb = (int)$q('SELECT bill_id FROM one_time_bills WHERE member_id = :m ORDER BY bill_id DESC LIMIT 1', ['m' => $E]);
$wOtb = $otb ? WaterPosting::byEvent('OTB-' . $otb) : null;
Checks::true('the one-time bill posts once the code is fixed', $wOtb !== null && $wOtb['status'] === 'posted');
if ($wOtb) {
    Checks::eq('one-time bill invoice total', 50.0, $money($q('SELECT total FROM transactions WHERE id = :t', ['t' => $wOtb['txn_id']])));
    Checks::eq('one-time bill credits the service fee income', $serviceFee, (int)$q('SELECT account_id FROM journal_lines WHERE txn_id = :t AND credit > 0', ['t' => $wOtb['txn_id']]));
}
Checks::eq('trial balance: debits = credits', 0.0, $tbDiff());

// installment contract on the corrected bill code: one invoice for the contract, the schedules collected against it
$admin->post('water/process/addInstallmentBill.php', ['csrf_token' => $admin->csrf(), 'member_id' => $E, 'bill_code_id' => $memCode, 'quantity' => 1, 'total_amount' => 600, 'term' => 3,
    'payment_mode' => 'monthly', 'amortization_type' => 'fixed', 'start_date' => $today] + $org);
$inst = (int)$q('SELECT installment_id FROM installment_bills WHERE member_id = :m ORDER BY installment_id DESC LIMIT 1', ['m' => $E]);
$wInst = $inst ? WaterPosting::byEvent('INST-' . $inst) : null;
Checks::true('installment contract posted as one invoice', $wInst !== null && $wInst['status'] === 'posted', $inst ? 'contract ' . $inst : 'no contract saved');
if ($wInst) {
    Checks::eq('installment invoice = contract total', $money($q('SELECT total_amount FROM installment_bills WHERE installment_id = :i', ['i' => $inst])), $money($q('SELECT total FROM transactions WHERE id = :t', ['t' => $wInst['txn_id']])));
    Checks::eq('three schedules', 3, (int)$q('SELECT COUNT(*) FROM installment_schedules WHERE installment_id = :i', ['i' => $inst]));
    $sched1 = $row('SELECT * FROM installment_schedules WHERE installment_id = :i ORDER BY due_date, schedule_id LIMIT 1', ['i' => $inst]);
    $admin->postJson('water/process/processUnifiedPayment.php', ['member_id' => $E, 'items' => [['type' => 'installment', 'id' => (int)$sched1['schedule_id']]], 'tendered' => $sched1['amount'],
        'transaction_date' => $today, 'idempotency_key' => 'e2e' . bin2hex(random_bytes(10))] + $org);
    Checks::true('first installment collected', ($admin->json['success'] ?? false) === true, $admin->json['error'] ?? substr($admin->body, 0, 200));
    Checks::eq('installment invoice open = contract less the first schedule', $money(600 - (float)$sched1['amount']), $openOf((int)$wInst['txn_id']));
}
// recurring plan: created and an account enrolled on the page (with its one-time form tokens), then the due bills generated
$tok = function (Browser $b, string $page, string $action): string {
    $b->get($page);
    foreach (preg_split('#</form>#i', $b->body) as $form) {
        if (preg_match('/name="action"\s+value="' . preg_quote($action, '/') . '"/', $form) && preg_match('/name="operation_token"\s+value="([0-9a-f]+)"/', $form, $m)) {
            return $m[1];
        }
    }
    return '';
};
$admin->post('water/views/recurring_bill.php', ['csrf_token' => $admin->csrf(), 'action' => 'create_plan', 'operation_token' => $tok($admin, 'water/views/recurring_bill.php', 'create_plan'),
    'bill_code_id' => $memCode, 'amount' => '75.00', 'frequency' => 'Monthly', 'first_billing_date' => $today, 'term_days' => 15]);
$plan = (int)$q('SELECT plan_id FROM recurring_plans ORDER BY plan_id DESC LIMIT 1');
Checks::true('recurring plan created on its page', $plan > 0);
$admin->post('water/views/recurring_bill.php', ['csrf_token' => $admin->csrf(), 'action' => 'enroll_member', 'operation_token' => $tok($admin, 'water/views/recurring_bill.php', 'enroll_member'),
    'plan_id' => $plan, 'member_id' => $F, 'subscription_start_date' => $today, 'catch_up_mode' => 'current_cycle'] + $org);
Checks::eq('account F enrolled', 1, (int)$q('SELECT COUNT(*) FROM recurring_subscriptions WHERE plan_id = :p AND member_id = :m', ['p' => $plan, 'm' => $F]));
$admin->post('water/views/recurring_bill.php', ['csrf_token' => $admin->csrf(), 'action' => 'generate_due_bills', 'operation_token' => $tok($admin, 'water/views/recurring_bill.php', 'generate_due_bills')] + $org);
$rbill = (int)$q('SELECT bill_id FROM recurring_bills WHERE member_id = :m ORDER BY bill_id DESC LIMIT 1', ['m' => $F]);
$wRb = $rbill ? WaterPosting::byEvent('RBILL-' . $rbill) : null;
Checks::true('the current-cycle recurring bill is generated and posted', $wRb !== null && $wRb['status'] === 'posted', $rbill ? 'bill ' . $rbill : 'no bill generated');
$admin->post('water/views/recurring_bill.php', ['csrf_token' => $admin->csrf(), 'action' => 'generate_due_bills', 'operation_token' => $tok($admin, 'water/views/recurring_bill.php', 'generate_due_bills')] + $org);
Checks::eq('generating again does not bill the same cycle twice', 1, (int)$q('SELECT COUNT(*) FROM recurring_bills WHERE member_id = :m AND plan_id = :p', ['m' => $F, 'p' => $plan]));
Checks::eq('trial balance: debits = credits', 0.0, $tbDiff());

// ======================================================================================== 7.3 inventory
Checks::section('7.3 Inventory: procurement, receipt, stock, issuance to the field, return, sale of a meter, valuation, A/P');
$meterCode = (int)$q("SELECT code_id FROM bill_codes WHERE code = 'WATER METER'");
$meterItem = (int)$q('SELECT item_id FROM bill_codes WHERE code_id = :c', ['c' => $meterCode]);
Checks::true('the inventory bill code WATER METER has its item in the one item master', $meterItem > 0);
$site = WaterPosting::siteId();
$vendor = (int)$q("SELECT v.id FROM party_crosswalk x JOIN vendors v ON v.party_id = x.party_id WHERE x.source = 'water_supp' ORDER BY x.source_id LIMIT 1");
Checks::true('a former water supplier is a supplier (vendor) of the cooperative with a party', $vendor > 0);
$qty0 = (float)$q('SELECT COALESCE(SUM(qty_on_hand), 0) FROM item_site_stock WHERE item_id = :i', ['i' => $meterItem]);
// purchase on the cooperative's Enter Bills page
$admin->post('index.php?p=vend.bills&a=new', ['_csrf' => $admin->csrf(), 'save' => 1, 'txn_date' => $today, 'vendor_id' => $vendor, 'site_id' => $site, 'due_date' => $today, 'ref_no' => 'SI-E2E-1',
    'lines' => [['item_id' => $meterItem, 'description' => 'Water meter 1/2"', 'qty' => 10, 'rate' => 750, 'amount' => 7500, 'site_id' => $site]]]);
$billId = (int)$q("SELECT id FROM transactions WHERE txn_type = 'bill' AND ref_no = 'SI-E2E-1' ORDER BY id DESC LIMIT 1");
Checks::true('purchase of 10 meters entered on Enter Bills', $billId > 0, preg_match('/alert-danger[^>]*>(.*?)<\/div>/s', $admin->body, $mm) ? trim(strip_tags($mm[1])) : $admin->url);
Checks::eq('stock on hand +10 at the water site (one inventory ledger)', $qty0 + 10, (float)$q('SELECT COALESCE(SUM(qty_on_hand), 0) FROM item_site_stock WHERE item_id = :i', ['i' => $meterItem]));
Checks::eq('A/P: the supplier is owed 7,500.00', 7500.0, $billId ? $openOf($billId) : 0.0);
// issuance to field work, on the water Material Issuance page
$expense = (int)$q("SELECT id FROM accounts WHERE category = 'Expense' AND is_active = 1 AND name LIKE '%Repair%' ORDER BY code LIMIT 1") ?: (int)Accts::get('default_expense');
$admin->post('water/process/saveItemIssuance.php', ['csrf_token' => $admin->csrf(), 'issuance_date' => $today, 'recipient' => 'Field crew 1', 'department' => 'Operations', 'purpose' => 'New connections (e2e)',
    'expense_account_id' => $expense, 'code_id' => [$meterCode], 'quantity' => [2]] + $org);
$iss = (int)$q('SELECT issuance_id FROM inventory_issuances ORDER BY issuance_id DESC LIMIT 1');
$wIss = $iss ? WaterPosting::byEvent('ISS-' . $iss) : null;
Checks::true('2 meters issued to the field: an inventory adjustment of the cooperative inventory', $wIss !== null && $wIss['status'] === 'posted');
Checks::eq('stock on hand after the issuance', $qty0 + 8, (float)$q('SELECT COALESCE(SUM(qty_on_hand), 0) FROM item_site_stock WHERE item_id = :i', ['i' => $meterItem]));
if ($wIss) {
    Checks::eq('issuance charged to the expense account at average cost (2 x 750)', 1500.0, $money($q('SELECT COALESCE(SUM(debit), 0) FROM journal_lines WHERE txn_id = :t AND account_id = :a', ['t' => $wIss['txn_id'], 'a' => $expense])));
    Checks::true('issuance document balanced', $txnBalanced((int)$wIss['txn_id']));
}
// one meter comes back unused: the issuance is reversed (returned) on its page, and issued again with 1
$admin->post('water/process/reverseItemIssuance.php', ['csrf_token' => $admin->csrf(), 'issuance_id' => $iss, 'reason' => 'One meter returned unused (e2e)']);
Checks::eq('reversal returns the stock', $qty0 + 10, (float)$q('SELECT COALESCE(SUM(qty_on_hand), 0) FROM item_site_stock WHERE item_id = :i', ['i' => $meterItem]));
Checks::true('the issuance document is voided and kept', $wIss && (int)$q('SELECT is_voided FROM transactions WHERE id = :t', ['t' => $wIss['txn_id']]) === 1);
$admin->post('water/process/saveItemIssuance.php', ['csrf_token' => $admin->csrf(), 'issuance_date' => $today, 'recipient' => 'Field crew 1', 'department' => 'Operations', 'purpose' => 'New connection (e2e)',
    'expense_account_id' => $expense, 'code_id' => [$meterCode], 'quantity' => [1]] + $org);
Checks::eq('issued again with 1 meter', $qty0 + 9, (float)$q('SELECT COALESCE(SUM(qty_on_hand), 0) FROM item_site_stock WHERE item_id = :i', ['i' => $meterItem]));
// a meter sold to a consumer on a one-time bill: the bill code is fixed first (its legacy A/R was an equipment account)
$salesIncome = (int)$q("SELECT id FROM accounts WHERE category = 'Income' AND is_active = 1 AND name LIKE '%Sales%' ORDER BY code LIMIT 1") ?: (int)Accts::get('sales_income');
$bc = $row('SELECT * FROM bill_codes WHERE code_id = :c', ['c' => $meterCode]);
$admin->post('water/process/editBillCode.php', ['csrf_token' => $admin->csrf(), 'code_id' => $meterCode, 'code' => $bc['code'], 'description' => $bc['description'], 'type' => $bc['type'],
    'ar_account' => $waterAr, 'income_account' => $salesIncome, 'cogs_account' => (int)Accts::get('cogs'), 'asset_account' => (int)Accts::get('inventory_asset'), 'item_id' => $meterItem,
    'cost_price' => $bc['cost_price'], 'sales_price' => $bc['sales_price'], 'allow_member_discount' => 0]);
$admin->post('water/process/addOneTimeBill.php', ['csrf_token' => $admin->csrf(), 'member_id' => $C, 'bill_code_id' => $meterCode, 'quantity' => 1, 'total_amount' => 1200, 'bill_date' => $today, 'due_date' => $today] + $org);
$otbM = (int)$q('SELECT bill_id FROM one_time_bills WHERE member_id = :m AND bill_code_id = :c ORDER BY bill_id DESC LIMIT 1', ['m' => $C, 'c' => $meterCode]);
$wOtbM = $otbM ? WaterPosting::byEvent('OTB-' . $otbM) : null;
Checks::true('meter sold on a one-time bill and posted', $wOtbM !== null && $wOtbM['status'] === 'posted', $otbM ? 'bill ' . $otbM : 'no bill');
Checks::eq('stock on hand after the sale', $qty0 + 8, (float)$q('SELECT COALESCE(SUM(qty_on_hand), 0) FROM item_site_stock WHERE item_id = :i', ['i' => $meterItem]));
if ($wOtbM) {
    Checks::eq('cost of the meter sold (COGS 750.00) on the same invoice', 750.0, $money($q('SELECT COALESCE(SUM(debit), 0) FROM journal_lines WHERE txn_id = :t AND account_id = :a', ['t' => $wOtbM['txn_id'], 'a' => (int)Accts::get('cogs')])));
}
$val = $money($q('SELECT COALESCE(SUM(total_cost), 0) FROM inventory_movements WHERE item_id = :i', ['i' => $meterItem]));
Checks::eq('stock valuation: 8 meters x 750.00', 6000.0, $val);
$assetGl = $money($q('SELECT COALESCE(SUM(j.debit - j.credit), 0) FROM journal_lines j WHERE j.account_id = :a AND j.item_id = :i', ['a' => (int)Accts::get('inventory_asset'), 'i' => $meterItem]));
Checks::eq('inventory asset in the GL = stock valuation for the item', $val, $assetGl);
$admin->get('index.php?p=rpt.inv_valuation_summary&asof=' . $today);
Checks::true('Inventory Valuation Summary opens and lists the meter', $admin->status === 200 && str_contains($admin->body, (string)$q('SELECT code FROM items WHERE id = :i', ['i' => $meterItem])));
// the supplier is paid on Pay Bills
$bank = (int)$q("SELECT id FROM accounts WHERE account_type = 'Bank' AND is_active = 1 AND name LIKE '%Bank%' ORDER BY code LIMIT 1") ?: $cash;
$admin->post('index.php?p=vend.pay_bills', ['_csrf' => $admin->csrf(), 'txn_date' => $today, 'account_id' => $bank, 'payment_method_id' => (int)$q("SELECT id FROM payment_methods WHERE name = 'Check'"),
    'pay' => [$billId => ['selected' => 1, 'amount' => 7500]]]);
Checks::eq('supplier paid on Pay Bills: the bill is settled', 0.0, $billId ? $openOf($billId) : -1.0);
Checks::eq('A/P owed to the supplier', 0.0, $money(Balances::vendorBalance($vendor)));
Checks::eq('trial balance: debits = credits', 0.0, $tbDiff());

// ======================================================================================== account opening and parties
Checks::section('7.2b Opening a water-service account: party on file, duplicate check, no automatic membership');
$acctA = $row('SELECT * FROM water_accounts WHERE pkey = :p', ['p' => $A]);
$partyA = WaterAccounts::ensureParty($A);
$acctB = $row('SELECT * FROM water_accounts WHERE pkey = :p', ['p' => $B]);
$cityId = (int)$q("SELECT pkey FROM cities WHERE status = 'A' ORDER BY pkey LIMIT 1");
$membersBefore = (int)$q('SELECT COUNT(*) FROM members');
$registerBefore = (int)$q('SELECT COUNT(*) FROM membershipinfo');
$accountsBefore = (int)$q('SELECT COUNT(*) FROM water_accounts');
$openForm = fn(array $extra) => array_merge(['csrf_token' => $admin->csrf(), 'gender' => '', 'civilstatus' => '', 'zone' => '', 'meter_sequence' => '',
    'rc_id' => (int)$acctA['rc_id'], 'pipe_size_id' => (int)$acctA['pipe_size_id'], 'connection_date' => $today, 'housebldg' => '', 'street' => 'E2E Street',
    'other_address_detail' => '', 'city_id' => $cityId, 'zippostal_code' => 0], $extra);

$admin->get('water/process/searchParties.php?q=' . rawurlencode(mb_substr((string)$acctB['lastname'], 0, 6)));
Checks::true('the party search of the Open Account form finds the holder of account B', in_array((int)WaterAccounts::ensureParty($B), array_column($admin->json['parties'] ?? [], 'id'), true), substr($admin->body, 0, 200));

// a second connection of the holder of account A, opened on A's party
$admin->post('water/process/addMember.php', $openForm(['firstname' => (string)$acctA['firstname'], 'middlename' => (string)$acctA['middlename'], 'lastname' => (string)$acctA['lastname'], 'link_party_id' => $partyA]));
$newA = (int)$q('SELECT MAX(pkey) FROM water_accounts');
Checks::eq('the second account is opened', $accountsBefore + 1, (int)$q('SELECT COUNT(*) FROM water_accounts'));
Checks::eq('...on the party chosen (one party, two water-service accounts)', $partyA, (int)$q('SELECT party_id FROM water_accounts WHERE pkey = :p', ['p' => $newA]));
Checks::eq('the party now holds two water-service accounts', 2, count(WaterAccounts::ofParty($partyA)));
$admin->get('index.php?p=party.center&id=' . $partyA);
Checks::true('the Party / Member Center lists both accounts on the water tab', str_contains($admin->body, (string)$acctA['account_number']) && str_contains($admin->body, (string)$q('SELECT account_number FROM water_accounts WHERE pkey = :p', ['p' => $newA])));

// the same person typed again without choosing the party: refused until the clerk decides
$admin->post('water/process/addMember.php', $openForm(['firstname' => (string)$acctB['firstname'], 'middlename' => (string)$acctB['middlename'], 'lastname' => (string)$acctB['lastname']]));
Checks::eq('a consumer who looks like a party on file is not given a second party silently (nothing saved)', $accountsBefore + 1, (int)$q('SELECT COUNT(*) FROM water_accounts'));
$admin->get('water/views/member_list.php');
Checks::true('...the page says why and offers the party on file', str_contains($admin->body, 'may already be on file') && str_contains($admin->body, 'Use this party'));
$admin->post('water/process/addMember.php', $openForm(['firstname' => (string)$acctB['firstname'], 'middlename' => (string)$acctB['middlename'], 'lastname' => (string)$acctB['lastname'], 'distinct_party' => '1']));
$newB = (int)$q('SELECT MAX(pkey) FROM water_accounts');
Checks::eq('with "This is a different person" the account opens', $accountsBefore + 2, (int)$q('SELECT COUNT(*) FROM water_accounts'));
Checks::true('...with a party of its own', (int)$q('SELECT party_id FROM water_accounts WHERE pkey = :p', ['p' => $newB]) > 0 && (int)$q('SELECT party_id FROM water_accounts WHERE pkey = :p', ['p' => $newB]) !== (int)WaterAccounts::ensureParty($B));
Checks::eq('opening water accounts makes nobody a cooperative member (members)', $membersBefore, (int)$q('SELECT COUNT(*) FROM members'));
Checks::eq('...and writes nothing to the former membership register', $registerBefore, (int)$q('SELECT COUNT(*) FROM membershipinfo'));
Checks::true('the new parties are not members', !Party::isMember($partyA) && !Party::isMember((int)$q('SELECT party_id FROM water_accounts WHERE pkey = :p', ['p' => $newB])));

// ======================================================================================== permissions
Checks::section('7.4b Permissions: a cashier collects, cannot post billing or open the ledger set-up');
$cashierRole = (int)$q("SELECT id FROM roles WHERE name = 'Cashier'");
$uid = (int)$q("SELECT id FROM users WHERE username = 'e2ecashier'");
if (!$uid) {
    $uid = DB::insert('users', ['username' => 'e2ecashier', 'full_name' => 'E2E Cashier', 'email' => 'e2e@example.test', 'password_hash' => Auth::hash($PASS2), 'status' => 'active',
        'must_change_password' => 0, 'password_changed_at' => date('Y-m-d H:i:s'), 'coop_cash_account_id' => $cash]);
    DB::insert('user_roles', ['user_id' => $uid, 'role_id' => $cashierRole]);
}
$cas = new Browser($base, 'cashier');
Checks::true('cashier signs in', $cas->login('e2ecashier', $PASS2));
$cas->get('water/views/water_bills_collection.php');
Checks::true('cashier opens Collections (Cashier)', $cas->status === 200 && str_contains($cas->url, 'water_bills_collection'), $cas->url);
$cas->get('water/views/generate_soa.php');
Checks::true('cashier cannot open Post Billing (access denied page, 403)', $cas->status === 403 || !str_contains($cas->url, 'generate_soa'), $cas->status . ' ' . $cas->url);
Checks::true('...and the denied page says so', str_contains($cas->body, 'ccess denied') || str_contains($cas->body, 'not allowed') || str_contains($cas->body, 'permission'));
$cas->postJson('water/process/postBilling.php', ['action' => 'post', 'period_id' => $pid, 'filter' => 'water', 'billing_date' => $today, 'term_days' => 15] + $org);
Checks::eq('cashier posting billing directly to the endpoint is refused (403)', 403, $cas->status);
$cas->get('index.php?p=coa.journal');
Checks::eq('cashier cannot open Make Journal Entries (403)', 403, $cas->status);
$cas->postJson('water/process/processUnifiedPayment.php', ['member_id' => $F, 'items' => [['type' => 'water', 'id' => $readings[$F]['id']]], 'tendered' => $readings[$F]['due'], 'transaction_date' => $today, 'idempotency_key' => 'e2e' . bin2hex(random_bytes(10))] + $org);
Checks::true('cashier collects account F', ($cas->json['success'] ?? false) === true, $cas->json['error'] ?? substr($cas->body, 0, 200));
$rF = (string)($cas->json['receipt_no'] ?? '');
Checks::eq('the receipt records the cashier who collected it', $uid, (int)$q('SELECT cashier_id FROM payment_transactions WHERE receipt_no = :r', ['r' => $rF]));
Checks::eq('the payment document is attributed to the cashier', $uid, (int)$q('SELECT t.created_by FROM water_postings w JOIN transactions t ON t.id = w.txn_id WHERE w.event_key = :k', ['k' => WaterPosting::collectionKey($rF)]));
$noCsrf = new Browser($base, 'nocsrf');
$noCsrf->login('e2ecashier', $PASS2);
$ch = $noCsrf->handle('POST', 'water/process/processUnifiedPayment.php', ['member_id' => $F, 'items' => [], 'idempotency_key' => 'x' . bin2hex(random_bytes(10))], true);
$noCsrf->finish($ch, curl_exec($ch));
Checks::eq('a request without the CSRF token is refused (403, code csrf_invalid; 419 is not an Apache status)', 403, $noCsrf->status);
Checks::true('...and says the form session expired', str_contains((string)($noCsrf->json['code'] ?? ''), 'csrf') || stripos($noCsrf->body, 'expired') !== false, substr($noCsrf->body, 0, 160));

// ======================================================================================== workbook v12 (version 41)
Checks::section('v41 Member search, Ladder Rate Billing, several charges on one form, a bill issued again, receipt amounts, a collection period with times');
// the member search of Bill Readings (individual) and of the Statement of Account: it failed on every search before
$acA = $row('SELECT pkey, lastname, firstname, account_number FROM water_accounts WHERE pkey = :p', ['p' => $A]);
$admin->get('water/process/searchMembers.php?context=reading_entry&period_id=' . $pid . '&q=' . rawurlencode(trim($acA['firstname'] . ' ' . $acA['lastname'])));
$found = array_map('intval', array_column($admin->json, 'pkey'));
Checks::true('Bill Readings: the member search finds the account by its first and last name', $admin->status === 200 && in_array($A, $found, true), substr($admin->body, 0, 200));
$admin->get('water/process/searchMembers.php?context=reading_entry&period_id=' . $pid . '&q=' . rawurlencode(trim($acA['lastname'] . ', ' . $acA['firstname'])));
Checks::true('...and by "last name, first name"', in_array($A, array_map('intval', array_column($admin->json, 'pkey')), true), substr($admin->body, 0, 200));
if ((string)$acA['account_number'] !== '') {
    $admin->get('water/process/searchMembers.php?context=generate_soa&q=' . rawurlencode((string)$acA['account_number']));
    $hit = array_values(array_filter($admin->json, fn($m) => (int)$m['pkey'] === $A));
    Checks::true('Statement of Account: the member search finds the account by its account number, and shows it', $hit && ($hit[0]['account_number'] ?? '') === (string)$acA['account_number'], substr($admin->body, 0, 200));
}
$admin->get('water/process/searchMembers.php?context=generate_soa&q=' . rawurlencode('zzqx' . bin2hex(random_bytes(3))));
Checks::true('a name on no account finds nothing (an empty list, not an error)', $admin->status === 200 && $admin->json === [] && trim($admin->body) === '[]', substr($admin->body, 0, 120));

// Ladder Rate Billing on Rate Codes: each tier's own cubic meters at that tier's rate, added up
$rcA = (int)$q('SELECT rc_id FROM water_accounts WHERE pkey = :p', ['p' => $A]);
$rcRow = $row('SELECT * FROM rate_code WHERE rc_id = :r', ['r' => $rcA]);
Checks::eq('a rate code on file keeps Straight Rate Billing after the upgrade', 'straight', (string)$rcRow['billing_method']);
$straight = compute_water_billing_details(DB::pdo(), $A, 45, true);
$admin->get('water/views/rate_codes.php');
$rcTok = preg_match('#action="\.\./process/editRateCode\.php".*?name="operation_token" value="([0-9a-f]+)"#s', $admin->body, $mm) ? $mm[1] : '';
$admin->post('water/process/editRateCode.php', ['csrf_token' => $admin->csrf(), 'operation_token' => $rcTok, 'rc_id' => $rcA, 'rc_code' => $rcRow['rc_code'], 'rc_name' => $rcRow['rc_name'], 'billing_method' => 'ladder']);
Checks::eq('Rate Codes: the computation is saved as Ladder Rate Billing on its page', 'ladder', (string)$q('SELECT billing_method FROM rate_code WHERE rc_id = :r', ['r' => $rcA]));
$ladder = compute_water_billing_details(DB::pdo(), $A, 45, true);
$expect = 0.0;
foreach (array_values($ladder['rate_tiers']) as $i => $t) {
    $from = (int)$t['from_cb'];
    $to = $t['to_cb'] === null ? PHP_INT_MAX : (int)$t['to_cb'];
    if ($i > 0 && 45 < $from) {
        break;
    }
    $expect += strtoupper((string)$t['bill_type']) === 'FIXED' ? (float)$t['amount'] : round((float)$t['amount'] * max(0, min(45, $to) - $from + 1), 2);
}
Checks::eq('45 m3 on Ladder Rate Billing = each tier\'s cubic meters at its rate, added up', $money($expect), $money($ladder['base_charge']));
Checks::true('...below the Straight Rate Billing of the same 45 m3 (all of it at the rate of the tier it falls in)', $money($ladder['base_charge']) < $money($straight['base_charge']), $ladder['base_charge'] . ' / ' . $straight['base_charge']);
Checks::eq('the bill says how it was computed', 'Ladder Rate Billing', (string)$ladder['billing_method_label']);
$admin->get('water/process/getWaterRates.php');
$rateRows = array_values(array_filter((array)($admin->json['rates'] ?? $admin->json), fn($r) => is_array($r) && (int)($r['rc_id'] ?? 0) === $rcA));
Checks::true('the live preview of Bill Readings gets the computation of the rate code', $rateRows && ($rateRows[0]['billing_method'] ?? '') === 'ladder', substr($admin->body, 0, 160));
$admin->get('water/views/rate_codes.php');
$rcTok = preg_match('#action="\.\./process/editRateCode\.php".*?name="operation_token" value="([0-9a-f]+)"#s', $admin->body, $mm) ? $mm[1] : '';
$admin->post('water/process/editRateCode.php', ['csrf_token' => $admin->csrf(), 'operation_token' => $rcTok, 'rc_id' => $rcA, 'rc_code' => $rcRow['rc_code'], 'rc_name' => $rcRow['rc_name'], 'billing_method' => 'straight']);
Checks::eq('...and back to Straight Rate Billing: the same bill as before', $money($straight['base_charge']), $money(compute_water_billing_details(DB::pdo(), $A, 45, true)['base_charge']));

// Issue Individual Charge: several charge categories at once, each with its own amount (a material by its quantity)
$billsBefore = (int)$q('SELECT COUNT(*) FROM one_time_bills WHERE member_id = :m', ['m' => $C]);
$stockBefore = (float)$q('SELECT COALESCE(SUM(qty_on_hand), 0) FROM item_site_stock WHERE item_id = :i', ['i' => $meterItem]);
$admin->post('water/process/addOneTimeBill.php', ['csrf_token' => $admin->csrf(), 'member_id' => $C, 'bill_date' => $today, 'due_date' => $today,
    'charges' => [$memCode => ['on' => '1', 'amount' => '75.50'], $meterCode => ['on' => '1', 'quantity' => '1', 'amount' => '1']]] + $org);
$multi = DB::all('SELECT * FROM one_time_bills WHERE member_id = :m ORDER BY bill_id DESC LIMIT 2', ['m' => $C]);
Checks::eq('two charges ticked on one form: two one-time bills', $billsBefore + 2, (int)$q('SELECT COUNT(*) FROM one_time_bills WHERE member_id = :m', ['m' => $C]));
$byCode = array_column($multi, null, 'bill_code_id');
Checks::eq('...the typed amount of the first charge', 75.5, $money($byCode[$memCode]['total_amount'] ?? 0));
Checks::eq('...the material at its sales price times the quantity (not the amount sent)', $money($q('SELECT sales_price FROM bill_codes WHERE code_id = :c', ['c' => $meterCode])), $money($byCode[$meterCode]['total_amount'] ?? 0));
Checks::eq('...each with its own invoice', 2, count(array_filter($multi, fn($b) => (WaterPosting::byEvent('OTB-' . $b['bill_id'])['status'] ?? '') === 'posted')));
Checks::eq('...the stock of the material goes down by its quantity', $stockBefore - 1, (float)$q('SELECT COALESCE(SUM(qty_on_hand), 0) FROM item_site_stock WHERE item_id = :i', ['i' => $meterItem]));
Checks::eq('...and both carry the class chosen', 0, (int)$q('SELECT COUNT(*) FROM one_time_bills WHERE bill_id IN (' . implode(',', array_map('intval', array_column($multi, 'bill_id'))) . ') AND COALESCE(class_id, 0) <> :c', ['c' => $classId]));
$admin->post('water/process/addOneTimeBill.php', ['csrf_token' => $admin->csrf(), 'member_id' => $C, 'bill_date' => $today, 'due_date' => $today, 'charges' => []] + $org);
Checks::eq('no charge ticked: nothing is issued', $billsBefore + 2, (int)$q('SELECT COUNT(*) FROM one_time_bills WHERE member_id = :m', ['m' => $C]));

// Update Bill Record: a new charge category and date issued issue the unpaid bill again
$fee = $byCode[$memCode] ?? null;
if ($fee) {
    $yesterday = date('Y-m-d', strtotime($today . ' -1 day'));
    $origPost = WaterPosting::byEvent('OTB-' . $fee['bill_id']);
    $admin->post('water/process/editOneTimeBill.php', ['csrf_token' => $admin->csrf(), 'bill_id' => $fee['bill_id'], 'bill_code_id' => $meterCode, 'bill_date' => $yesterday,
        'quantity' => '1', 'total_amount' => '1', 'due_date' => $today, 'status' => 'unpaid'] + $org);
    $after = $row('SELECT * FROM one_time_bills WHERE bill_id = :b', ['b' => $fee['bill_id']]);
    Checks::eq('the charge category of the bill changed', $meterCode, (int)$after['bill_code_id']);
    Checks::eq('...and its date issued', $yesterday, substr((string)$after['bill_date'], 0, 10));
    Checks::eq('...its amount follows the new category (a material at its sales price)', $money($q('SELECT sales_price FROM bill_codes WHERE code_id = :c', ['c' => $meterCode])), $money($after['total_amount']));
    Checks::eq('the first invoice is voided as replaced, not deleted', 'replaced', (string)$q('SELECT status FROM water_postings WHERE id = :i', ['i' => $origPost['id']]));
    $rev = WaterPosting::byEvent('OTB-' . $fee['bill_id'] . '-R1');
    Checks::true('the corrected invoice -R1 is posted on the new date issued', $rev && $rev['status'] === 'posted' && (string)$q('SELECT txn_date FROM transactions WHERE id = :t', ['t' => $rev['txn_id']]) === $yesterday);
    Checks::eq('...crediting the income account of the new category', (int)$q('SELECT income_account FROM bill_codes WHERE code_id = :c', ['c' => $meterCode]),
        (int)$q('SELECT account_id FROM journal_lines WHERE txn_id = :t AND credit > 0 AND account_id = :a LIMIT 1', ['t' => $rev['txn_id'] ?? 0, 'a' => (int)$q('SELECT income_account FROM bill_codes WHERE code_id = :c', ['c' => $meterCode])]));
    Checks::eq('...and the material leaves the stock', $stockBefore - 2, (float)$q('SELECT COALESCE(SUM(qty_on_hand), 0) FROM item_site_stock WHERE item_id = :i', ['i' => $meterItem]));
    Checks::true('the re-issue is in the audit trail with the old and new values', (bool)$q("SELECT COUNT(*) FROM water_audit_logs WHERE action = 'REISSUE_ONE_TIME_BILL' AND record_id = :b", ['b' => (string)$fee['bill_id']]));
}
// a bill lowered before (its own credit memo, not a collection) can be issued again: the credit memo goes with it
$admin->post('water/process/addOneTimeBill.php', ['csrf_token' => $admin->csrf(), 'member_id' => $D, 'bill_date' => $today, 'due_date' => $today,
    'charges' => [$memCode => ['on' => '1', 'amount' => '100.00']]] + $org);
$low = $row('SELECT * FROM one_time_bills WHERE member_id = :m ORDER BY bill_id DESC LIMIT 1', ['m' => $D]);
$admin->post('water/process/editOneTimeBill.php', ['csrf_token' => $admin->csrf(), 'bill_id' => $low['bill_id'], 'bill_code_id' => $memCode, 'bill_date' => $today,
    'quantity' => '1', 'total_amount' => '80.00', 'due_date' => $today, 'status' => 'unpaid'] + $org);
$cm = $row("SELECT w.* FROM water_postings w JOIN transactions t ON t.id = w.txn_id WHERE w.source_type = 'one_time_bill' AND w.source_id = :b AND t.txn_type = 'credit_memo'", ['b' => (string)$low['bill_id']]);
Checks::true('a bill lowered from 100.00 to 80.00 gets a credit memo', $cm && $cm['status'] === 'posted');
$admin->post('water/process/editOneTimeBill.php', ['csrf_token' => $admin->csrf(), 'bill_id' => $low['bill_id'], 'bill_code_id' => $memCode, 'bill_date' => date('Y-m-d', strtotime($today . ' -2 day')),
    'quantity' => '1', 'total_amount' => '80.00', 'due_date' => $today, 'status' => 'unpaid'] + $org);
$r1 = WaterPosting::byEvent('OTB-' . $low['bill_id'] . '-R1');
Checks::true('...then given another date issued: issued again for 80.00, the invoice and its credit memo replaced', $r1 && $r1['status'] === 'posted'
    && $money($q('SELECT total FROM transactions WHERE id = :t', ['t' => $r1['txn_id']])) === 80.0
    && (string)$q('SELECT status FROM water_postings WHERE id = :i', ['i' => $cm['id'] ?? 0]) === 'replaced', substr(preg_replace('/\s+/', ' ', strip_tags($admin->body)), 0, 200));
Checks::eq('...the A/R of the bill is the 80.00 once', 80.0, $money(array_sum(array_map(fn($w) => $openOf((int)$w['txn_id']), WaterPosting::billPostings('one_time', (int)$low['bill_id'])))));
$admin->post('water/process/editOneTimeBill.php', ['csrf_token' => $admin->csrf(), 'bill_id' => $low['bill_id'], 'bill_code_id' => $memCode, 'bill_date' => $today,
    'quantity' => '1', 'total_amount' => '-5', 'due_date' => $today, 'status' => 'unpaid'] + $org);
Checks::eq('a negative amount is refused', 80.0, $money($q('SELECT total_amount FROM one_time_bills WHERE bill_id = :b', ['b' => $low['bill_id']])));

// a collected bill keeps its category and date
$admin->postJson('water/process/processUnifiedPayment.php', ['member_id' => $E, 'items' => [['type' => 'onetime', 'id' => $otb]], 'tendered' => 50, 'transaction_date' => $today, 'idempotency_key' => 'e2e' . bin2hex(random_bytes(10))] + $org);
Checks::true('the one-time bill of account E is collected', ($admin->json['success'] ?? false) === true, $admin->json['error'] ?? substr($admin->body, 0, 200));
$paidBill = $row('SELECT * FROM one_time_bills WHERE bill_id = :b', ['b' => $otb]);
$admin->post('water/process/editOneTimeBill.php', ['csrf_token' => $admin->csrf(), 'bill_id' => $otb, 'bill_code_id' => $meterCode, 'bill_date' => $paidBill['bill_date'],
    'quantity' => '1', 'total_amount' => $paidBill['total_amount'], 'due_date' => $paidBill['due_date'] ?: $today, 'status' => 'paid'] + $org);
Checks::eq('changing the category of a collected bill is refused', $memCode, (int)$q('SELECT bill_code_id FROM one_time_bills WHERE bill_id = :b', ['b' => $otb]));
Checks::true('...with the reason on the page', str_contains($admin->body, 'unpaid bill only') || str_contains($admin->body, 'Payments are recorded'), substr(preg_replace('/\s+/', ' ', strip_tags($admin->body)), 0, 160));

// Official receipt: the paid bill-code amounts are changed only by a user allowed to
$admin->get('water/views/generate_official_receipt.php?receipt_no=' . rawurlencode($rF));
Checks::true('the administrator can change the receipt amounts before printing', $admin->status === 200 && str_contains($admin->body, 'service-amount-input') && !preg_match('/class="service-amount-input"[^>]*readonly/s', $admin->body));
$cas->get('water/views/generate_official_receipt.php?receipt_no=' . rawurlencode($rF));
Checks::true('a cashier without the permission sees them read-only', $cas->status === 200 && preg_match('/class="service-amount-input"[^>]*readonly/s', $cas->body) === 1 && preg_match('/id="editTotalAmount"[^>]*readonly/s', $cas->body) === 1, $cas->status . ' ' . $cas->url);
DB::run("INSERT INTO role_special_permissions (role_id, perm_key, perm_value) VALUES (:r, 'water_receipt_amounts', '1')", ['r' => $cashierRole]);
$cas->get('water/views/generate_official_receipt.php?receipt_no=' . rawurlencode($rF));
Checks::true('...and can change them once the role is given the permission', $cas->status === 200 && !preg_match('/class="service-amount-input"[^>]*readonly/s', $cas->body));
DB::run("DELETE FROM role_special_permissions WHERE role_id = :r AND perm_key = 'water_receipt_amounts'", ['r' => $cashierRole]);

// Collection Summary over a period of dates and times
// the receipts of today (recorded today, for today): whole days count them by business date, times by the moment recorded
$recs = DB::all("SELECT receipt_no, payment_date AS at FROM payment_transactions
                  WHERE transaction_status = 'active' AND COALESCE(business_date, DATE(payment_date)) = :d AND DATE(payment_date) = :d2 ORDER BY at", ['d' => $today, 'd2' => $today]);
$inBody = fn(string $body) => count(array_filter($recs, fn($r) => str_contains($body, (string)$r['receipt_no'])));
$admin->get('index.php?p=rpt.water_collection_summary&dates=custom&from=' . $today . 'T00:00&to=' . $today . 'T23:59');
Checks::eq('Collection Summary: the whole day (00:00 to 23:59) lists every receipt of the day', count($recs), $inBody($admin->body));
Checks::true('...the From and To fields take a date and time', str_contains($admin->body, 'type="datetime-local" step="60" name="from"'));
$first = $recs ? strtotime((string)$recs[0]['at']) : time();
$admin->get('index.php?p=rpt.water_collection_summary&dates=custom&from=' . $today . 'T00:00&to=' . date('Y-m-d\TH:i', $first - 60));
Checks::eq('...a period ending before the first receipt\'s time lists none', 0, $inBody($admin->body));
$admin->get('index.php?p=rpt.water_collection_summary&dates=custom&from=' . date('Y-m-d\TH:i', $first) . '&to=' . $today . 'T23:59');
Checks::eq('...a period starting at its minute lists them all again', count($recs), $inBody($admin->body));
Checks::true('...and the receipts show their time', $recs && str_contains($admin->body, date('h:i A', $first)));
Checks::eq('trial balance: debits = credits', 0.0, $tbDiff());

// ======================================================================================== Fixed Share Capital Billing
Checks::section('7.5 Fixed Share Capital Billing: the member\'s fixed contribution, posted on its day with no journal entry, collected by the water cashier');
// the share capital product and a member with an open share capital account, the same person (party) as water account $C
$scProduct = (int)$q("SELECT id FROM sav_products WHERE product_type = 'share_capital' AND is_active = 1 ORDER BY id LIMIT 1");
if (!$scProduct) {
    $scProduct = (int)DB::insert('sav_products', ['code' => '03', 'name' => 'Paid Up Share Capital - Common', 'product_type' => 'share_capital', 'is_active' => 1, 'calendar_days' => 365,
        'liability_account_id' => (int)$q("SELECT id FROM accounts WHERE code = '3010'"), 'par_value' => '100', 'share_class' => 'common', 'min_deposit' => '100', 'allow_withdrawal' => 1]);
}
if (!(int)$q('SELECT COUNT(*) FROM coop_branches')) {
    DB::insert('coop_branches', ['code' => 'HO', 'name' => 'Head Office', 'address' => 'Head office', 'is_head_office' => 1, 'or_prefix' => 'HO', 'is_active' => 1]);
}
$partyC = (int)$q('SELECT party_id FROM water_accounts WHERE pkey = :p', ['p' => $C]);
$scMember = DB::tx(fn() => Members::save(['member_kind' => 'individual', 'last_name' => 'Bond', 'first_name' => 'James', 'middle_name' => '', 'suffix' => '',
    'branch_id' => (int)$q('SELECT id FROM coop_branches ORDER BY is_head_office DESC, id LIMIT 1'), 'member_type_id' => (int)$q("SELECT id FROM member_types WHERE code = 'REG'"),
    'birth_date' => '1980-04-13', 'gender' => 'M', 'civil_status' => 'married', 'nationality' => 'Filipino', 'res_street' => 'Mabini Street', 'res_barangay' => 'San Roque', 'res_city' => 'San Isidro',
    'res_province' => 'Nueva Ecija', 'occupation' => 'Driver', 'employment_type' => 'employed', 'membership_date' => '2024-01-15', 'pmes_date' => '2024-01-08', 'subscribed_shares' => 100,
    'par_value' => '100', 'allow_duplicate' => 1, 'link_party_id' => $partyC], [], null, 'coop.member'));
$_POST['waive_capital'] = '1';
Members::changeStatus($scMember, 'active', '2024-01-15', 'Approved by the Board (e2e)', 'BR-2024-01', 'coop.members');
unset($_POST['waive_capital']);
$scAcct = Savings::open($scMember, $scProduct, null, '2024-01-15', null, 'sav.accounts');
DB::run("UPDATE sav_accounts SET status = 'active' WHERE id = :a", ['a' => $scAcct]);
Checks::eq('the member is the same party as water account C', $partyC, (int)$q('SELECT party_id FROM members WHERE id = :m', ['m' => $scMember]));

$admin->get('water/views/share_capital_billing.php');
Checks::true('Water Billing > Billing > Fixed Share Capital Billing opens', $admin->status === 200 && str_contains($admin->body, 'Fixed Share Capital Billing') && !preg_match('/(Fatal error|Warning:|Notice:)/', $admin->body), $admin->status . ' ' . $admin->url);
Checks::true('...in the menu after One-Time Billing', (bool)preg_match('/one_time_billing\.php.*?share_capital_billing\.php/s', $admin->body));
Checks::true('...with the transaction code upon collection (CSD) and the class', str_contains($admin->body, 'name="txn_code"') && str_contains($admin->body, '>CSD Cash Savings Deposit<') && str_contains($admin->body, 'name="class_id"'));
Checks::true('...offering the member (an open share capital account)', str_contains($admin->body, 'Bond, James'));
$scToken = fn() => preg_match('/name="operation_token" value="([0-9a-f]+)"/', $admin->get('water/views/share_capital_billing.php')->body, $m) ? $m[1] : '';
$admin->post('water/process/saveShareCapitalBilling.php', ['csrf_token' => $admin->csrf(), 'operation_token' => $scToken(), 'action' => 'settings', 'txn_code' => 'CSD', 'class_id' => $classId]);
Checks::eq('the collection settings saved: CSD, the class', 'CSD/' . $classId, $q("SELECT setting_value FROM water_billing_settings WHERE setting_key = 'share_capital_txn_code'") . '/' . $q("SELECT setting_value FROM water_billing_settings WHERE setting_key = 'share_capital_class_id'"));
$txnsBefore = (int)$q('SELECT COUNT(*) FROM transactions');
$start = date('Y-m-01', strtotime($today . ' -1 month'));
$admin->post('water/process/saveShareCapitalBilling.php', ['csrf_token' => $admin->csrf(), 'operation_token' => $scToken(), 'action' => 'save', 'member_id' => $scMember, 'sav_account_id' => $scAcct,
    'frequency' => 'monthly', 'start_date' => $start, 'posting_day' => 1, 'amount' => '50']);
$plan = $row('SELECT * FROM share_capital_plans WHERE sav_account_id = :a', ['a' => $scAcct]);
Checks::true('James Bond on fixed billing: monthly, 50.00, posting day 1', $plan && $plan['frequency'] === 'monthly' && $plan['amount'] === '50.00' && (int)$plan['posting_day'] === 1, substr(preg_replace('/\s+/', ' ', strip_tags($admin->body)), 0, 300));
$scBills = DB::all('SELECT * FROM share_capital_bills WHERE plan_id = :p ORDER BY bill_date', ['p' => $plan['plan_id'] ?? 0]);
Checks::eq('the bills of last month and this month are posted at once', 2, count($scBills));
Checks::eq('...with NO journal entry', $txnsBefore, (int)$q('SELECT COUNT(*) FROM transactions'));
$admin->post('water/process/saveShareCapitalBilling.php', ['csrf_token' => $admin->csrf(), 'operation_token' => $scToken(), 'action' => 'save', 'member_id' => $scMember, 'sav_account_id' => $scAcct,
    'frequency' => 'monthly', 'start_date' => $start, 'posting_day' => 1, 'amount' => '50']);
Checks::eq('a second billing of the same account is refused', 1, (int)$q("SELECT COUNT(*) FROM share_capital_plans WHERE sav_account_id = :a AND status <> 'deleted'", ['a' => $scAcct]));
$admin->post('water/process/saveShareCapitalBilling.php', ['csrf_token' => $admin->csrf(), 'operation_token' => 'stale', 'action' => 'hold', 'plan_id' => $plan['plan_id'], 'reason' => 'x']);
Checks::eq('a stale form is refused (operation token)', 'active', (string)$q('SELECT status FROM share_capital_plans WHERE plan_id = :p', ['p' => $plan['plan_id']]));
$admin->post('water/process/saveShareCapitalBilling.php', ['csrf_token' => $admin->csrf(), 'operation_token' => $scToken(), 'action' => 'hold', 'plan_id' => $plan['plan_id'], 'reason' => 'Paused (e2e)']);
Checks::eq('Hold: the billing stops', 'on_hold', (string)$q('SELECT status FROM share_capital_plans WHERE plan_id = :p', ['p' => $plan['plan_id']]));
$admin->post('water/process/saveShareCapitalBilling.php', ['csrf_token' => $admin->csrf(), 'operation_token' => $scToken(), 'action' => 'resume', 'plan_id' => $plan['plan_id']]);
Checks::true('Resume: active again, the next bill on the next posting day', $q('SELECT status FROM share_capital_plans WHERE plan_id = :p', ['p' => $plan['plan_id']]) === 'active'
    && $q('SELECT next_bill_date FROM share_capital_plans WHERE plan_id = :p', ['p' => $plan['plan_id']]) === date('Y-m-01', strtotime(date('Y-m-01', strtotime($today)) . ' +1 month')));
$cas->get('water/views/share_capital_billing.php');
$casMay = (int)$q("SELECT COUNT(*) FROM role_page_access r JOIN pages p ON p.id = r.page_id WHERE p.page_key = 'water.share_capital_billing' AND r.role_id = :r AND r.allowed = 1", ['r' => $cashierRole]);
Checks::true('the page follows the rights of the roles (a cashier ' . ($casMay ? 'with' : 'without') . ' One-Time Billing)', $casMay ? $cas->status === 200 : ($cas->status === 403 || !str_contains($cas->url, 'share_capital_billing')), $cas->status . ' ' . $cas->url);

// the cashier: the bills are open items of water account C
$admin->get('water/process/searchAllReceivables.php?q=' . rawurlencode((string)$q('SELECT lastname FROM water_accounts WHERE pkey = :p', ['p' => $C])));
Checks::true('the cashier finds account C with its share capital bills among the open items', in_array($C, array_map(fn($r) => (int)$r['pkey'], (array)$admin->json), true));
$admin->get('water/process/getAllReceivables.php?member_id=' . $C);
$scItems = array_values(array_filter((array)$admin->json, fn($i) => ($i['db_type'] ?? '') === 'sharecap'));
Checks::eq('...two share capital bills listed at the cashier', 2, count($scItems));
$admin->postJson('water/process/processUnifiedPayment.php', ['member_id' => $C, 'items' => [['type' => 'sharecap', 'id' => (int)$scBills[0]['bill_id'], 'payment_amount' => 50]], 'tendered' => 50,
    'transaction_date' => $today, 'idempotency_key' => 'e2e' . bin2hex(random_bytes(10))] + $org);
$scRec = (string)($admin->json['receipt_no'] ?? '');
Checks::true('the cashier collects the first bill (50.00, under the product\'s minimum deposit)', ($admin->json['success'] ?? false) === true, $admin->json['error'] ?? substr($admin->body, 0, 200));
$col = $row('SELECT * FROM share_capital_collections WHERE receipt_no = :r', ['r' => $scRec]);
$sv = $row('SELECT * FROM sav_txns WHERE id = :i', ['i' => $col['sav_txn_id'] ?? 0]);
Checks::true('...a CSD deposit on the Savings Account Transaction, the receipt as OR, waiting for the passbook', $sv && $sv['txn_code'] === 'CSD' && $sv['credit'] === '50.00' && $sv['or_no'] === $scRec && (int)$sv['pb_printed'] === 0);
$lines = DB::all('SELECT a.code, l.debit, l.credit, l.class_id FROM journal_lines l JOIN accounts a ON a.id = l.account_id WHERE l.txn_id = :t ORDER BY l.debit DESC', ['t' => $col['ledger_txn_id'] ?? 0]);
Checks::eq('...its journal entry: Dr the cashier\'s cash 50.00, Cr share capital 50.00, in the class of the page', [['11118', '50.00', '0.00', $classId], ['3010', '0.00', '50.00', $classId]],
    array_map(fn($l) => [$l['code'], $l['debit'], $l['credit'], (int)$l['class_id']], $lines));
Checks::eq('...the bill paid', 'paid', (string)$q('SELECT status FROM share_capital_bills WHERE bill_id = :b', ['b' => $scBills[0]['bill_id']]));
$admin->get('water/views/collection_receipt.php?receipt_no=' . rawurlencode($scRec));
Checks::true('the receipt prints FIXED SHARE CAPITAL 50.00', $admin->status === 200 && str_contains($admin->body, 'FIXED SHARE CAPITAL') && str_contains($admin->body, '50.00'));

// the collection reports
$admin->get('index.php?p=rpt.water_collection_summary&dates=custom&from=' . $today . '&to=' . $today);
Checks::true('Collection Summary (Summary): a Share capital column beside Bills paid', str_contains($admin->body, '>Share capital<') && str_contains($admin->body, '>Bills paid<') && str_contains($admin->body, $scRec));
Checks::true('...and the Report type drop-down (Summary, Detailed)', (bool)preg_match('/name="rtype".*?>Summary<.*?>Detailed</s', $admin->body));
$admin->get('index.php?p=rpt.water_collection_summary&dates=custom&from=' . $today . '&to=' . $today . '&rtype=detailed');
$heads = preg_match_all('/<th[^>]*>(.*?)<\/th>/s', $admin->body, $hm) ? array_map(fn($h) => trim(strip_tags($h)), $hm[1]) : [];
Checks::true('Collection Summary (Detailed): a column for each code name collected (' . implode(', ', array_slice(array_filter($heads, fn($h) => $h !== '' && strtoupper($h) === $h), 0, 8)) . ')',
    in_array('FIXED SHARE CAPITAL', $heads, true) && !in_array('Bills paid', $heads, true));
$detail = Report::get('water_collection_summary');
$saved = $_GET;
$_GET = ['dates' => 'custom', 'from' => $today, 'to' => $today, 'rtype' => 'detailed'];
$det = ($detail['run'])(Report::filters($detail));
$_GET = ['dates' => 'custom', 'from' => $today, 'to' => $today];
$sum = ($detail['run'])(Report::filters($detail));
$_GET = $saved;
Checks::eq('...the same total collected as the Summary', $sum['totals']['total'], $det['totals']['total']);
$codeCols = array_values(array_filter($det['columns'], fn($c) => ($c['type'] ?? '') === 'money' && !in_array($c['key'], ['penalty', 'compromise', 'total'], true)));
$bad = 0;
foreach ($det['rows'] as $r) {
    $s = 0.0;
    foreach ($codeCols as $c) {
        $s += (float)$r[$c['key']];
    }
    $bad += abs($s + (float)$r['penalty'] + (float)$r['compromise'] - (float)$r['total']) > 0.004 ? 1 : 0;
}
Checks::eq('...each receipt: its code names, penalty and compromise add up to its total (' . count($det['rows']) . ' receipts, ' . count($codeCols) . ' code names)', 0, $bad);

// the receipt corrected: the deposit taken back (ECSD), the bill open again
$admin->post('water/process/collectionCorrectionProcess.php', ['csrf_token' => $admin->csrf(), 'receipt_no' => $scRec, 'action' => 'error_correction', 'business_date' => $today, 'reason' => 'Wrong account keyed (e2e)'] + $org);
$col = $row('SELECT * FROM share_capital_collections WHERE receipt_no = :r', ['r' => $scRec]);
Checks::true('Collection Corrections reverses the receipt of share capital only', $col && $col['status'] === 'reversed' && $q('SELECT transaction_status FROM payment_transactions WHERE receipt_no = :r', ['r' => $scRec]) === 'reversed',
    substr(preg_replace('/\s+/', ' ', strip_tags($admin->body)), 0, 300));
Checks::eq('...ECSD takes the deposit back', 'ECSD/-50.00', $q('SELECT CONCAT(txn_code, \'/\', credit) FROM sav_txns WHERE id = :i', ['i' => $col['reversal_sav_txn_id'] ?? 0]));
Checks::eq('...the bill unpaid again', 'unpaid', (string)$q('SELECT status FROM share_capital_bills WHERE bill_id = :b', ['b' => $scBills[0]['bill_id']]));
$admin->post('water/process/saveShareCapitalBilling.php', ['csrf_token' => $admin->csrf(), 'operation_token' => $scToken(), 'action' => 'delete', 'plan_id' => $plan['plan_id'], 'reason' => 'End of the e2e test']);
Checks::eq('Delete: the billing deleted, its two unpaid bills cancelled', 'deleted/2', $q('SELECT status FROM share_capital_plans WHERE plan_id = :p', ['p' => $plan['plan_id']]) . '/'
    . $q("SELECT COUNT(*) FROM share_capital_bills WHERE plan_id = :p AND status = 'cancelled'", ['p' => $plan['plan_id']]));
Checks::eq('trial balance: debits = credits', 0.0, $tbDiff());

// ======================================================================================== v14: Term (days), batch and the imports
Checks::section('7.6 Fixed Share Capital Billing: Term (days), Batch Share Capital Billing and its import; the import of One-Time Billing charges');
// members with an open share capital account, each the same party as a water account
$mkScMember = function (string $last, string $given, int $waterKey) use ($q, $scProduct) {
    $party = (int)$q('SELECT party_id FROM water_accounts WHERE pkey = :p', ['p' => $waterKey]);
    $m = DB::tx(fn() => Members::save(['member_kind' => 'individual', 'last_name' => $last, 'first_name' => $given, 'middle_name' => '', 'suffix' => '',
        'branch_id' => (int)$q('SELECT id FROM coop_branches ORDER BY is_head_office DESC, id LIMIT 1'), 'member_type_id' => (int)$q("SELECT id FROM member_types WHERE code = 'REG'"),
        'birth_date' => '1982-06-0' . rand(1, 9), 'gender' => 'M', 'civil_status' => 'married', 'nationality' => 'Filipino', 'res_street' => 'Rizal Street', 'res_barangay' => 'San Roque',
        'res_city' => 'San Isidro', 'res_province' => 'Nueva Ecija', 'occupation' => 'Farmer', 'employment_type' => 'self_employed', 'membership_date' => '2024-02-01',
        'pmes_date' => '2024-01-20', 'subscribed_shares' => 50, 'par_value' => '100', 'allow_duplicate' => 1, 'link_party_id' => $party], [], null, 'coop.member'));
    $_POST['waive_capital'] = '1';
    Members::changeStatus($m, 'active', '2024-02-01', 'Approved (e2e)', 'BR-2024-02', 'coop.members');
    unset($_POST['waive_capital']);
    $acct = Savings::open($m, $scProduct, null, '2024-02-01', null, 'sav.accounts');
    DB::run("UPDATE sav_accounts SET status = 'active' WHERE id = :a", ['a' => $acct]);
    return ['member' => $m, 'acct' => $acct, 'no' => (string)$q('SELECT account_no FROM sav_accounts WHERE id = :a', ['a' => $acct]), 'water' => $waterKey];
};
$m1 = $mkScMember('Jordan', 'Michael', $D);
$m2 = $mkScMember('Curry', 'Stephen', $E);
$m3 = $mkScMember('Pippen', 'Scottie', $F);
$m4 = $mkScMember('Rodman', 'Dennis', $A);
$mFirst = date('Y-m-01', strtotime($today));
// Term (days) on Add member
$admin->post('water/process/saveShareCapitalBilling.php', ['csrf_token' => $admin->csrf(), 'operation_token' => $scToken(), 'action' => 'save', 'member_id' => $m1['member'], 'sav_account_id' => $m1['acct'],
    'frequency' => 'monthly', 'start_date' => $mFirst, 'posting_day' => 1, 'amount' => '40', 'term_days' => '10']);
$p1 = $row('SELECT * FROM share_capital_plans WHERE sav_account_id = :a', ['a' => $m1['acct']]);
$b1 = $row('SELECT * FROM share_capital_bills WHERE plan_id = :p ORDER BY bill_date LIMIT 1', ['p' => $p1['plan_id'] ?? 0]);
Checks::true('Add member with Term (days) 10: the bill of the 1st is due on the 11th', $p1 && (int)$p1['term_days'] === 10 && $b1 && $b1['due_date'] === date('Y-m-d', strtotime($mFirst . ' +10 days')),
    ($b1['due_date'] ?? '-') . ' ' . substr(preg_replace('/\s+/', ' ', strip_tags($admin->body)), 0, 200));
$admin->get('water/process/getAllReceivables.php?member_id=' . $D);
$sc = array_values(array_filter((array)$admin->json, fn($i) => ($i['db_type'] ?? '') === 'sharecap'));
Checks::eq('the cashier shows its due date', date('Y-m-d', strtotime($mFirst . ' +10 days')), (string)($sc[0]['sc_due_date'] ?? ''));
$admin->postJson('water/process/processUnifiedPayment.php', ['member_id' => $D, 'items' => [['type' => 'sharecap', 'id' => (int)$b1['bill_id'], 'payment_amount' => 40]], 'tendered' => 40,
    'transaction_date' => $today, 'idempotency_key' => 'e2e' . bin2hex(random_bytes(10))] + $org);
Checks::true('...and collects it', ($admin->json['success'] ?? false) === true, $admin->json['error'] ?? substr($admin->body, 0, 200));
// Batch Share Capital Billing: two members, the same amount, frequency, start date, posting day and term
$admin->get('water/views/share_capital_billing.php');
Checks::true('the page offers Batch billing and Import, and the Term (days) field', str_contains($admin->body, 'Batch Share Capital Billing') && str_contains($admin->body, 'share_capital_import.php') && str_contains($admin->body, 'name="term_days"'));
$admin->post('water/process/saveShareCapitalBilling.php', ['csrf_token' => $admin->csrf(), 'operation_token' => $scToken(), 'action' => 'batch', 'accounts_json' => json_encode([$m2['acct'], $m3['acct']]),
    'amount' => '25', 'frequency' => 'monthly', 'start_date' => $mFirst, 'posting_day' => 1, 'term_days' => '5']);
$batch = DB::all('SELECT * FROM share_capital_plans WHERE sav_account_id IN (:a, :b) ORDER BY plan_id', ['a' => $m2['acct'], 'b' => $m3['acct']]);
Checks::true('Batch billing: both members on fixed billing, 25.00 monthly, term 5, one batch number', count($batch) === 2 && $batch[0]['batch_no'] && $batch[0]['batch_no'] === $batch[1]['batch_no']
    && $batch[0]['amount'] === '25.00' && (int)$batch[1]['term_days'] === 5, substr(preg_replace('/\s+/', ' ', strip_tags($admin->body)), 0, 200));
Checks::eq('...their bills of the 1st posted, due on the 6th', 2, (int)$q('SELECT COUNT(*) FROM share_capital_bills WHERE plan_id IN (' . implode(',', array_map(fn($p) => (int)$p['plan_id'], $batch) ?: [0]) . ') AND due_date = :d',
    ['d' => date('Y-m-d', strtotime($mFirst . ' +5 days'))]));
// the import of Batch Share Capital Billing
$upload = function (Browser $b, string $path, array $fields, string $file, string $type = 'text/csv') {
    $ch = $b->handle('POST', $path, [], false, ['X-CSRF-Token: ' . $b->csrf(), 'X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields + ['csrf_token' => $b->csrf(), 'file' => new CURLFile($file, $type, basename($file))]);
    return $b->finish($ch, curl_exec($ch));
};
$admin->get('water/views/share_capital_import.php');
Checks::true('Import Batch Share Capital Billing opens (the rights of Fixed Share Capital Billing)', $admin->status === 200 && str_contains($admin->body, 'Import Batch Share Capital Billing') && !preg_match('/(Fatal error|Warning:|Notice:)/', $admin->body));
$tpl = $admin->get('water/process/shareCapitalImport.php?action=template&format=csv')->body;
Checks::true('...its CSV template (the columns and example lines)', str_starts_with(ltrim($tpl, "\xEF\xBB\xBF"), 'account_no,member_name,amount,frequency,start_date,posting_day,term_days'));
$xl = $admin->get('water/process/shareCapitalImport.php?action=template&format=xlsx')->body;
Checks::true('...and its Excel template', str_starts_with($xl, 'PK'));
$csv = sys_get_temp_dir() . '/e2e_scimp_' . bin2hex(random_bytes(4)) . '.csv';
file_put_contents($csv, "Share Capital Account,Member Name,Amount,Term\n" . ltrim($m4['no'], '0') . ",\"Rodman, Dennis\",30,OPEN\n" . $m2['no'] . ",Curry Stephen,30,\n99999999999,Nobody,30,\n");
$imp = $upload($admin, 'water/process/shareCapitalImport.php', ['action' => 'read', 'frequency' => 'monthly', 'start_date' => $mFirst, 'posting_day' => '1', 'term_days' => '3'], $csv);
$lines = array_column((array)($admin->json['rows'] ?? []), null, 'line');
Checks::true('the file read for review: 3 lines, 2 with a problem (already on fixed billing; no such account), the leading zero lost in a spreadsheet found again',
    count($lines) === 3 && ($admin->json['bad'] ?? -1) === 2 && !($lines[2]['errors'] ?? ['x']) && str_contains(implode(' ', $lines[3]['errors'] ?? []), 'already on fixed billing'), substr($admin->body, 0, 400));
$keep = [['_line' => 2, 'account_no' => ltrim($m4['no'], '0'), 'member_name' => 'Rodman, Dennis', 'amount' => '30', 'frequency' => '', 'start_date' => '', 'posting_day' => '', 'term_days' => 'OPEN']];
$defaults = ['frequency' => 'monthly', 'start_date' => $mFirst, 'posting_day' => '1', 'term_days' => '3', 'amount' => ''];
$admin->postJson('water/process/shareCapitalImport.php', ['action' => 'check', 'rows' => $keep, 'defaults' => $defaults]);
Checks::eq('...the lines in red removed: checked again, none with a problem', 0, (int)($admin->json['bad'] ?? -1));
$admin->postJson('water/process/shareCapitalImport.php', ['action' => 'post', 'rows' => $keep, 'defaults' => $defaults, 'operation_token' => 'stale', 'file' => 'test.csv']);
Checks::true('...posting with a stale form is refused', ($admin->json['success'] ?? true) === false);
$impToken = preg_match('/token: "([0-9a-f]+)"/', $admin->get('water/views/share_capital_import.php')->body, $mm) ? $mm[1] : '';
$admin->postJson('water/process/shareCapitalImport.php', ['action' => 'post', 'rows' => $keep, 'defaults' => $defaults, 'operation_token' => $impToken, 'file' => 'test.csv']);
$p4 = $row('SELECT * FROM share_capital_plans WHERE sav_account_id = :a', ['a' => $m4['acct']]);
Checks::true('...posted: Rodman on fixed billing, 30.00 from the file, monthly from the form, no due date (OPEN), an import batch number', ($admin->json['success'] ?? false) === true && $p4 && $p4['amount'] === '30.00'
    && $p4['term_days'] === null && str_starts_with((string)$p4['batch_no'], 'SCI-'), $admin->json['error'] ?? substr($admin->body, 0, 200));
// the import of One-Time Billing charges
$admin->get('water/views/one_time_billing.php');
Checks::true('One-Time Billing offers Import Charges', str_contains($admin->body, 'one_time_billing_import.php'));
$admin->get('water/views/one_time_billing_import.php');
Checks::true('Import One-Time Charges opens', $admin->status === 200 && str_contains($admin->body, 'Import One-Time Charges') && !preg_match('/(Fatal error|Warning:|Notice:)/', $admin->body));
$acctNo = fn(int $k) => (string)($q('SELECT account_number FROM water_accounts WHERE pkey = :p', ['p' => $k]) ?: $k);
$stockBefore = (float)$q('SELECT COALESCE(SUM(qty_on_hand), 0) FROM item_site_stock WHERE item_id = (SELECT item_id FROM bill_codes WHERE code_id = :c)', ['c' => $meterCode]);
$csv2 = sys_get_temp_dir() . '/e2e_otbimp_' . bin2hex(random_bytes(4)) . '.csv';
file_put_contents($csv2, "Account,Charge Category,Amount,Qty,Term\n" . $acctNo($A) . ",MEMBERSHIP FEE,75.25,,\n" . $acctNo($B) . ",membership fee,,,OPEN\n" . $acctNo($C) . ",WATER METER,,1,30\n"
    . $acctNo($D) . ",WATER BILL,100,,\n" . $acctNo($E) . ",WATER METER,,9999,\n");
$billsBefore = (int)$q('SELECT COUNT(*) FROM one_time_bills');
$upload($admin, 'water/process/oneTimeBillImport.php', ['action' => 'read', 'bill_date' => $today, 'term_days' => '15', 'class_id' => (string)$classId], $csv2);
$lines = array_column((array)($admin->json['rows'] ?? []), null, 'line');
Checks::true('the charges read for review: 5 lines, the water bill\'s code and a quantity above the stock in red', count($lines) === 5 && ($admin->json['bad'] ?? -1) === 2
    && str_contains(implode(' ', $lines[5]['errors'] ?? []), 'water bill') && str_contains(implode(' ', $lines[6]['errors'] ?? []), 'Not enough stock'),
    json_encode(['bad' => $admin->json['bad'] ?? null] + array_map(fn($l) => $l['errors'], $lines)));
Checks::eq('...a blank amount takes the price of the bill code (MEMBERSHIP FEE 50.00), a material its price times the quantity', [75.25, 50.0, 1200.0],
    [(float)($lines[2]['figure'] ?? 0), (float)($lines[3]['figure'] ?? 0), (float)($lines[4]['figure'] ?? 0)]);
$keep2 = [];
foreach ([2, 3, 4] as $l) {
    $keep2[] = ['_line' => $l] + $lines[$l]['values'];
}
$otbDefaults = ['bill_date' => $today, 'term_days' => '15', 'class_id' => (string)$classId, 'charge_code' => '', 'amount' => ''];
$otbToken = preg_match('/token: "([0-9a-f]+)"/', $admin->get('water/views/one_time_billing_import.php')->body, $mm) ? $mm[1] : '';
$admin->postJson('water/process/oneTimeBillImport.php', ['action' => 'post', 'rows' => $keep2, 'defaults' => $otbDefaults, 'operation_token' => $otbToken, 'file' => 'charges.csv']);
Checks::true('Issue bills: the three good lines issued', ($admin->json['success'] ?? false) === true, $admin->json['error'] ?? substr($admin->body, 0, 300));
$new = DB::all('SELECT * FROM one_time_bills ORDER BY bill_id DESC LIMIT 3');
Checks::eq('...three one-time bills', $billsBefore + 3, (int)$q('SELECT COUNT(*) FROM one_time_bills'));
$byMember = array_column($new, null, 'member_id');
Checks::true('...each with its amount and due date (15 days, OPEN, 30 days)', isset($byMember[$A], $byMember[$B], $byMember[$C]) && $byMember[$A]['total_amount'] === '75.25'
    && $byMember[$A]['due_date'] === date('Y-m-d', strtotime($today . ' +15 days')) && $byMember[$B]['due_date'] === null && $byMember[$C]['due_date'] === date('Y-m-d', strtotime($today . ' +30 days')));
$posted = 0;
foreach ($new as $b) {
    $w = $row("SELECT w.*, t.total FROM water_postings w JOIN transactions t ON t.id = w.txn_id WHERE w.source_type = 'one_time_bill' AND w.source_id = :s AND w.status = 'posted' ORDER BY w.id LIMIT 1", ['s' => (string)$b['bill_id']]);
    $posted += $w && $money($w['total']) === $money($b['total_amount']) && $txnBalanced((int)$w['txn_id']) ? 1 : 0;
}
Checks::eq('...each with its invoice on the ledger (balanced, for its amount)', 3, $posted);
Checks::eq('...the water meter out of stock (1)', $stockBefore - 1, (float)$q('SELECT COALESCE(SUM(qty_on_hand), 0) FROM item_site_stock WHERE item_id = (SELECT item_id FROM bill_codes WHERE code_id = :c)', ['c' => $meterCode]));
$admin->postJson('water/process/oneTimeBillImport.php', ['action' => 'post', 'rows' => $keep2, 'defaults' => $otbDefaults, 'operation_token' => $otbToken, 'file' => 'charges.csv']);
Checks::true('...posting the same review again is refused (the form was used)', ($admin->json['success'] ?? true) === false && (int)$q('SELECT COUNT(*) FROM one_time_bills') === $billsBefore + 3);
$admin->postJson('water/process/oneTimeBillImport.php', ['action' => 'check', 'rows' => $keep2, 'defaults' => ['class_id' => ''] + $otbDefaults]);
Checks::true('no class chosen: said above the lines while checking, before anything is posted', str_contains(implode(' ', (array)($admin->json['general'] ?? [])), 'class'), substr($admin->body, 0, 300));
$postMax = (function (string $v) { $n = (float)$v; $u = strtoupper(substr(trim($v), -1)); return (int)($n * ($u === 'G' ? 1073741824 : ($u === 'M' ? 1048576 : ($u === 'K' ? 1024 : 1)))); })((string)ini_get('post_max_size'));
if ($postMax > 0 && $postMax < 64 * 1048576) {
    $huge = sys_get_temp_dir() . '/e2e_big_' . bin2hex(random_bytes(4)) . '.csv';
    $fh = fopen($huge, 'w');
    fwrite($fh, "account_no,amount\n");
    $line = str_repeat('1', 60) . ",1\n";
    for ($i = 0, $n = intdiv($postMax, strlen($line)) + 20000; $i < $n; $i++) {
        fwrite($fh, $line);
    }
    fclose($fh);
    $upload($admin, 'water/process/shareCapitalImport.php', ['action' => 'read'], $huge);
    Checks::true('a file larger than the server takes (' . ini_get('post_max_size') . ') is said to be too large', ($admin->json['success'] ?? true) === false && str_contains((string)($admin->json['error'] ?? ''), 'larger than'), substr($admin->body, 0, 200));
    @unlink($huge);
}
$cas->get('water/views/one_time_billing_import.php');
$casOtb = (int)$q("SELECT COUNT(*) FROM role_page_access r JOIN pages p ON p.id = r.page_id WHERE p.page_key = 'water.one_time_billing' AND r.role_id = :r AND r.allowed = 1", ['r' => $cashierRole]);
Checks::true('the import pages follow the rights of their page (a cashier ' . ($casOtb ? 'with' : 'without') . ' One-Time Billing)', $casOtb ? $cas->status === 200 : ($cas->status === 403 || !str_contains($cas->url, 'one_time_billing_import')), $cas->status . ' ' . $cas->url);
@unlink($csv);
@unlink($csv2);
Checks::eq('trial balance: debits = credits', 0.0, $tbDiff());

// ======================================================================================== v15: the joint account / institution form
Checks::section('7.7 Members: the joint account and institution form in the format of the former program');
$admin->get('index.php?p=coop.member&kind=joint');
$jb = $admin->body;
Checks::true('New member > Joint account opens on Joint/Institution Details and Membership Information', $admin->status === 200 && str_contains($jb, '>Joint/Institution Details<') && str_contains($jb, '>Membership Information<')
    && !preg_match('/(Fatal error|Warning:|Notice:)/', $jb), $admin->status . ' ' . $admin->url);
Checks::true('...Family and beneficiaries, Financial and Loan and savings grouping are not its tabs', (bool)preg_match('/<li class="nav-item" data-kinds="individual" hidden>.*?Family and beneficiaries/s', $jb)
    && substr_count($jb, 'data-kinds="individual" hidden') === 3);
Checks::true('...NEW ASSOCIATE - JOINT, the membership name, the representatives, the addresses and the contact list', str_contains($jb, '>NEW<') && str_contains($jb, 'ASSOCIATE - JOINT')
    && str_contains($jb, '>Membership name<') && str_contains($jb, 'Authorized representative name') && str_contains($jb, 'name="j_off_street"') && str_contains($jb, 'id="gJContacts"'));
$pbPeek = preg_match('/data-pb-get="([^"]+)"/', $jb, $mm) ? $mm[1] : '';
Checks::true('...and Get passbook number (' . $pbPeek . ')', $pbPeek !== '');
$jType = (int)$q("SELECT id FROM member_types WHERE member_kind = 'joint' AND is_active = 1 ORDER BY sort_order, id LIMIT 1");
$branch = (int)$q('SELECT id FROM coop_branches ORDER BY is_head_office DESC, id LIMIT 1');
$admin->post('index.php?p=coop.member', ['_csrf' => $admin->csrf(), 'action' => 'save', 'member_kind' => 'joint', 'member_no' => '', 'institution_name' => 'Garcia, Lorna or Ben or Mia',
    'representatives_sent' => '1', 'representatives' => ['e0' => ['name' => 'Lorna Garcia', 'position' => 'Wife', 'rep_member_id' => ''], 'e1' => ['name' => 'Ben Garcia', 'position' => 'Husband', 'rep_member_id' => ''],
        'e2' => ['name' => 'Mia Garcia', 'position' => 'Daughter', 'rep_member_id' => '']],
    'j_res_house' => '7', 'j_res_street' => 'Luna Street', 'j_res_barangay' => 'San Roque', 'j_res_city' => 'San Isidro', 'j_res_province' => 'Nueva Ecija', 'j_res_postal' => '3106',
    'j_off_street' => 'Market Road', 'j_off_city' => 'Cabanatuan', 'j_contacts_sent' => '1', 'j_contacts' => ['e0' => ['contact_type' => 'mobile', 'country_code' => '63', 'area_code' => '', 'number' => '09181112222', 'is_primary' => '1']],
    'j_email' => 'garcia@example.com', 'j_branch_id' => $branch, 'j_member_type_id' => $jType, 'j_membership_date' => $today, 'j_pmes_date' => $today, 'j_subscribed_shares' => '5', 'j_par_value' => '100',
    'j_passbook_no' => $pbPeek, 'j_passbook_series' => '1', 'j_passbook_peek' => $pbPeek, 'j_alert_note' => 'Two representatives sign each withdrawal',
    // what an individual's boxes would send is not read for a joint account
    'last_name' => 'Ignored', 'first_name' => 'Name', 'res_street' => 'Individual street', 'dependents_sent' => '1', 'dependents' => ['e0' => ['first_name' => 'Ignored']], 'allow_duplicate' => '1']);
$jm = $row("SELECT * FROM members WHERE institution_name = 'Garcia, Lorna or Ben or Mia' ORDER BY id DESC LIMIT 1");
Checks::true('Add member: a joint account, an applicant, its name the membership name, Associate - Joint', $jm && $jm['member_kind'] === 'joint' && $jm['full_name'] === 'Garcia, Lorna or Ben or Mia'
    && $jm['status'] === 'applicant' && (int)$jm['member_type_id'] === $jType, substr(preg_replace('/\s+/', ' ', strip_tags($admin->body)), 0, 300));
Checks::eq('...its representatives with their relation', ['Lorna Garcia (Wife)', 'Ben Garcia (Husband)', 'Mia Garcia (Daughter)'],
    DB::column("SELECT CONCAT(name, ' (', position, ')') FROM member_representatives WHERE member_id = :m ORDER BY id", ['m' => $jm['id'] ?? 0]));
Checks::eq('...the joint boxes saved (the individual\'s ignored): address, office, contact list, passbook from the series, alert', ['', 'Luna Street', 'Market Road', '09181112222', $pbPeek, 'Two representatives sign each withdrawal', 0],
    [(string)$jm['last_name'], $jm['res_street'], $jm['off_street'], $jm['mobile'], $jm['passbook_no'], $jm['alert_note'], (int)$q('SELECT COUNT(*) FROM member_dependents WHERE member_id = :m', ['m' => $jm['id']])]);
$admin->get('index.php?p=coop.member&id=' . $jm['id']);
Checks::true('...opened again on its own form: Member ID, APPLICANT, ASSOCIATE - JOINT', str_contains($admin->body, '>Joint/Institution Details<') && str_contains($admin->body, 'APPLICANT')
    && str_contains($admin->body, 'ASSOCIATE - JOINT') && str_contains($admin->body, 'value="Garcia, Lorna or Ben or Mia"'));
$admin->post('index.php?p=coop.member&id=' . $jm['id'], ['_csrf' => $admin->csrf(), 'action' => 'save', 'member_kind' => 'joint', 'institution_name' => 'Garcia, Lorna or Ben or Mia',
    'representatives_sent' => '1', 'representatives' => ['e0' => ['name' => 'Lorna Garcia', 'position' => 'Wife', 'rep_member_id' => '']], 'j_res_street' => 'Luna Street', 'j_branch_id' => $branch,
    'j_member_type_id' => $jType, 'j_passbook_no' => $pbPeek]);
Checks::eq('...a passbook number kept on a save; the representatives changed', [$pbPeek, 1], [(string)$q('SELECT passbook_no FROM members WHERE id = :m', ['m' => $jm['id']]),
    (int)$q('SELECT COUNT(*) FROM member_representatives WHERE member_id = :m', ['m' => $jm['id']])]);
// a refused save shows the joint boxes as typed; a member type of another kind is refused
$regType = (int)$q("SELECT id FROM member_types WHERE member_kind = 'individual' AND is_active = 1 ORDER BY id LIMIT 1");
$admin->post('index.php?p=coop.member', ['_csrf' => $admin->csrf(), 'action' => 'save', 'member_kind' => 'institutional', 'institution_name' => 'Pantabangan Rural Health Unit',
    'representatives_sent' => '1', 'representatives' => ['e0' => ['name' => 'Rosa Dizon', 'position' => 'Chief', 'rep_member_id' => '']], 'j_res_street' => 'Health Center Road', 'j_off_city' => 'Pantabangan',
    'j_email' => 'not-an-email@', 'j_branch_id' => $branch, 'j_member_type_id' => $jType, 'j_alert_note' => 'Typed before the error', 'allow_duplicate' => '1']);
Checks::true('a refused save (an e-mail that is not valid) shows the joint boxes as typed', str_contains($admin->body, 'The e-mail address is not valid') && str_contains($admin->body, 'value="Health Center Road"')
    && str_contains($admin->body, 'value="Pantabangan"') && str_contains($admin->body, 'value="Typed before the error"') && str_contains($admin->body, '>Joint/Institution Details<'));
$admin->post('index.php?p=coop.member', ['_csrf' => $admin->csrf(), 'action' => 'save', 'member_kind' => 'institutional', 'institution_name' => 'Pantabangan Rural Health Unit',
    'representatives_sent' => '1', 'j_branch_id' => $branch, 'j_member_type_id' => $regType, 'allow_duplicate' => '1']);
Checks::true('an institution with a member type of individuals is refused', str_contains($admin->body, 'Choose a member type of an institution')
    && !(int)$q("SELECT COUNT(*) FROM members WHERE institution_name = 'Pantabangan Rural Health Unit'"), substr(preg_replace('/\s+/', ' ', strip_tags($admin->body)), 0, 200));
$admin->get('index.php?p=coop.member&kind=institutional');
Checks::true('New member > Institution: NEW ASSOCIATE - INSTITUTIONAL', str_contains($admin->body, 'ASSOCIATE - INSTITUTIONAL') && str_contains($admin->body, '>Joint/Institution Details<'));
$admin->get('index.php?p=coop.member');
Checks::true('an individual keeps Personal, Family and beneficiaries, Financial, Membership and Loan and savings grouping', str_contains($admin->body, '>Family and beneficiaries<')
    && str_contains($admin->body, '>Loan and savings grouping<') && substr_count($admin->body, 'data-kinds="individual" hidden') === 0);

// ======================================================================================== an account with no first name
Checks::section('7.8 Water-service accounts with no first name (organizations, joint accounts, institutions): their name and account number on every list');
// an active organization account with a working tariff, given a reading of the open period
$org = null;
foreach (DB::column("SELECT pkey FROM water_accounts WHERE status = 'A' AND connection_status = 'active' AND rc_id IS NOT NULL AND (firstname IS NULL OR firstname = '')
                      AND lastname <> '' AND account_number <> CAST(pkey AS CHAR) ORDER BY pkey LIMIT 60") as $id) {
    try {
        if ((float)compute_water_billing_details(DB::pdo(), (int)$id, 12, true)['amount_due'] > 0) {
            $org = $row('SELECT pkey AS member_id, account_number, lastname FROM water_accounts WHERE pkey = :p', ['p' => $id]) + ['period_id' => $pid];
            break;
        }
    } catch (Throwable $e) {
    }
}
Checks::true('the former water database has accounts with no first name (' . (int)$q("SELECT COUNT(*) FROM water_accounts WHERE firstname IS NULL OR firstname = ''") . '), one with a working tariff: ' . ($org['lastname'] ?? '-'), (bool)$org);
if ($org) {
    $admin->postJson('water/process/saveIndividualReading.php', ['period_id' => $pid, 'member_id' => (int)$org['member_id'], 'pres_reading' => 12]);
    Checks::true('...a reading saved for it', ($admin->json['success'] ?? false) === true, $admin->json['error'] ?? substr($admin->body, 0, 200));
}
if ($org) {
    $admin->get('water/process/getPeriodReadings.php?period_id=' . $org['period_id']);
    $hit = array_values(array_filter((array)($admin->json['readings'] ?? []), fn($r) => (int)$r['member_id'] === (int)$org['member_id']));
    Checks::eq('Reading Entry, Recorded Readings: the name and the account number (not "—" and the ID)', [$org['lastname'], $org['account_number']], [(string)($hit[0]['full_name'] ?? ''), (string)($hit[0]['account_number'] ?? '')]);
    $admin->get('water/process/getReadingHistory.php?context=reading_history&q=' . rawurlencode($org['account_number']));
    $hit = array_values(array_filter((array)($admin->json['readings'] ?? []), fn($r) => (int)$r['member_id'] === (int)$org['member_id']));
    Checks::true('Reading History: found by its account number, with its name', $hit && $hit[0]['full_name'] === $org['lastname'] && (string)$hit[0]['account_number'] === (string)$org['account_number'], substr($admin->body, 0, 200));
    $admin->get('water/process/searchAllReceivables.php?q=' . rawurlencode(mb_substr($org['lastname'], 0, 12)));
    $hit = array_values(array_filter((array)$admin->json, fn($r) => (int)$r['pkey'] === (int)$org['member_id']));
    Checks::true('Collections (Cashier) search: its name', !$hit || $hit[0]['full_name'] === $org['lastname'], json_encode($hit[0] ?? null));
    // the reading was only for these lists: deleted on Reading Entry (an unposted reading, version 46), so the period closes as before
    Checks::section('v46 Reading Entry: an unposted reading edited or deleted; a posted one locked');
    $rid = (int)$q('SELECT reading_id FROM bill_readings WHERE member_id = :m AND period_id = :p AND is_billed = 0', ['m' => (int)$org['member_id'], 'p' => $pid]);
    $admin->get('water/process/getPeriodReadings.php?period_id=' . $pid);
    $byId = array_column((array)($admin->json['readings'] ?? []), null, 'reading_id');
    Checks::true('Recorded Readings: the unposted reading can be edited and deleted', !empty($byId[$rid]['draft_editable']), json_encode($byId[$rid] ?? null));
    $postedRid = (int)$q('SELECT reading_id FROM bill_readings WHERE period_id = :p AND is_billed = 1 ORDER BY reading_id LIMIT 1', ['p' => $pid]);
    Checks::true('...a posted reading is locked, with the reason', $postedRid && empty($byId[$postedRid]['draft_editable']) && (string)($byId[$postedRid]['lock_reason'] ?? '') !== '', json_encode($byId[$postedRid] ?? null));
    $admin->postJson('water/process/saveIndividualReading.php', ['period_id' => $pid, 'member_id' => (int)$org['member_id'], 'pres_reading' => 15, 'reading_id' => $rid]);
    Checks::eq('Edit: the unposted reading is saved again with the new present reading', 15, (int)$q('SELECT pres_reading FROM bill_readings WHERE reading_id = :r', ['r' => $rid]));
    $nc = new Browser($base, 'nocsrf46');
    $nc->login('admin', $PASS2);
    $ch = $nc->handle('POST', 'water/process/deleteReading.php', ['period_id' => $pid, 'reading_id' => $rid, 'reason' => 'No token sent'], true);
    $nc->finish($ch, curl_exec($ch));
    Checks::eq('Delete without the security token is refused (403)', 403, $nc->status);
    $admin->postJson('water/process/deleteReading.php', ['period_id' => $pid, 'reading_id' => $rid, 'reason' => 'bad']);
    Checks::true('Delete needs a reason of at least 5 characters', ($admin->json['success'] ?? true) === false && str_contains((string)($admin->json['message'] ?? ''), '5 characters'), $admin->body);
    $admin->postJson('water/process/deleteReading.php', ['period_id' => $pid, 'reading_id' => $postedRid, 'reason' => 'Trying a posted reading']);
    Checks::true('a posted reading cannot be deleted', ($admin->json['success'] ?? true) === false && (int)$q('SELECT COUNT(*) FROM bill_readings WHERE reading_id = :r', ['r' => $postedRid]) === 1, $admin->body);
    $cas->postJson('water/process/deleteReading.php', ['period_id' => $pid, 'reading_id' => $rid, 'reason' => 'A cashier tries']);
    Checks::eq('a cashier (no Delete right on Reading Entry) is refused (403)', 403, $cas->status);
    $admin->postJson('water/process/deleteReading.php', ['period_id' => $pid, 'reading_id' => $rid, 'reason' => 'Only for the list checks']);
    Checks::true('Delete: the unposted reading is deleted', ($admin->json['success'] ?? false) === true && !(int)$q('SELECT COUNT(*) FROM bill_readings WHERE reading_id = :r', ['r' => $rid]), $admin->body);
    Checks::true('...with its reason in the audit log', str_contains((string)$q("SELECT details FROM water_audit_logs WHERE action = 'DELETE_METER_READING' AND record_id = :r ORDER BY log_id DESC LIMIT 1", ['r' => (string)$rid]), 'Only for the list checks'));
}

// ======================================================================================== workbook v16 (version 46)
Checks::section('v46 Regular - Joint, address sub-groups, incremental searching, pre-printed receipts');
$admin->get('index.php?p=coop.member&kind=joint');
Checks::true('New member > Joint account: the Type list offers Regular - Joint beside Associate - Joint', str_contains($admin->body, '>Regular - Joint<') && str_contains($admin->body, '>Associate - Joint<'));
foreach (['water/views/address_subgroups.php' => 'Address Sub-groups', 'water/views/receipt_layouts.php' => 'Receipt Layout Setup', 'water/views/receipt_layouts.php?kind=official' => 'Official receipt'] as $path => $needle) {
    $admin->get($path);
    Checks::true("$path opens", $admin->status === 200 && str_contains($admin->body, $needle), $admin->status . ' ' . $admin->url);
}
$admin->get('water/views/settings.php');
Checks::true('Water Settings links the two new pages', str_contains($admin->body, 'receipt_layouts.php') && str_contains($admin->body, 'address_subgroups.php'));
// the address sub-groups over their endpoint
$sg = 'water/process/addressSubgroups.php';
$admin->get($sg . '?action=list');
Checks::true('Address sub-groups: the list', ($admin->json['success'] ?? false) === true && is_array($admin->json['rows'] ?? null), substr($admin->body, 0, 200));
$name = 'E2E Purok ' . substr(bin2hex(random_bytes(3)), 0, 5);
$admin->postJson($sg, ['action' => 'save', 'name' => $name, 'sort_order' => 7, 'is_active' => true, 'page_key' => 'water.address_subgroups']);
$sgRow = array_values(array_filter((array)($admin->json['rows'] ?? []), fn($r) => $r['name'] === $name))[0] ?? null;
Checks::true('...added', ($admin->json['success'] ?? false) === true && $sgRow !== null, substr($admin->body, 0, 200));
$admin->postJson($sg, ['action' => 'save', 'name' => strtoupper($name), 'sort_order' => 1, 'is_active' => true, 'page_key' => 'water.address_subgroups']);
Checks::true('...the same name again is refused', ($admin->json['success'] ?? true) === false && str_contains((string)($admin->json['error'] ?? ''), 'already'), substr($admin->body, 0, 200));
$acct = (int)$q("SELECT pkey FROM water_accounts WHERE status = 'A' ORDER BY pkey LIMIT 1");
DB::run('UPDATE water_accounts SET address_subgroup = :n WHERE pkey = :k', ['n' => $name, 'k' => $acct]);
$admin->postJson($sg, ['action' => 'save', 'id' => $sgRow['id'] ?? 0, 'name' => $name . ' B', 'sort_order' => 7, 'is_active' => true, 'page_key' => 'water.address_subgroups']);
Checks::eq('...renamed, on the water-service account that has it', $name . ' B', (string)$q('SELECT address_subgroup FROM water_accounts WHERE pkey = :k', ['k' => $acct]));
$admin->postJson($sg, ['action' => 'delete', 'id' => $sgRow['id'] ?? 0, 'page_key' => 'water.address_subgroups']);
Checks::true('...deleting it while an account has it is refused', ($admin->json['success'] ?? true) === false && str_contains((string)($admin->json['error'] ?? ''), 'cannot be deleted'), substr($admin->body, 0, 200));
DB::run('UPDATE water_accounts SET address_subgroup = NULL WHERE pkey = :k', ['k' => $acct]);
$admin->postJson($sg, ['action' => 'delete', 'id' => $sgRow['id'] ?? 0, 'page_key' => 'water.address_subgroups']);
Checks::true('...deleted once nothing has it', ($admin->json['success'] ?? false) === true && !(int)$q('SELECT COUNT(*) FROM coop_lists WHERE id = :i', ['i' => $sgRow['id'] ?? 0]), substr($admin->body, 0, 200));
$ch = $nc->handle('POST', $sg, ['action' => 'save', 'name' => 'No token'], true);
$nc->finish($ch, curl_exec($ch));
Checks::eq('...a change without the security token is refused (403)', 403, $nc->status);
// incremental searching: one letter is enough; the names that start with it come first
$admin->get('water/process/searchMembers.php?context=reading_entry&period_id=' . $pid . '&q=a');
$names = array_map(fn($r) => mb_strtolower(trim((string)($r['lastname'] ?? ''))), (array)$admin->json);
$firstOther = null;
foreach ($names as $i => $n) {
    if (!str_starts_with($n, 'a')) {
        $firstOther = $i;
        break;
    }
}
Checks::true('Member search: one letter is enough, the last names that start with it first', $admin->status === 200 && $names && ($firstOther === null || !array_filter(array_slice($names, $firstOther), fn($n) => str_starts_with($n, 'a'))), implode(', ', array_slice($names, 0, 8)));
// the pre-printed receipts: the layout saved and put back; both receipts printed on their forms
$admin->post('water/views/receipt_layouts.php?kind=collection', ['csrf_token' => $admin->csrf(), 'kind' => 'collection', 'reason' => 'E2E layout',
    'setup' => array_merge(WaterReceiptLayout::defaultLayout('collection')['setup'], ['margin_top' => 144]), 'L' => ['header' => [['f' => 'account', 'x' => 600, 'y' => 1452, 'w' => 1134, 'a' => 'c']],
    'body' => [['f' => 'description', 'x' => 2484, 'y' => 0], ['f' => 'amount', 'x' => 5087, 'y' => 0, 'w' => 794, 'a' => 'r', 'fmt' => 'comma']], 'footer' => [['f' => 'amount_due', 'x' => 4208, 'y' => 0, 'w' => 1663, 'a' => 'r']]]]);
$saved = json_decode((string)$q("SELECT setting_value FROM settings WHERE setting_key = 'water_receipt_layout_collection'"), true);
Checks::true('Receipt Layout Setup: the layout of the water bill is saved', $admin->status === 200 && ($saved['header'][0]['x'] ?? null) === 600 && ($saved['setup']['margin_top'] ?? null) === 144, substr(strip_tags($admin->body), 0, 160));
$admin->get('water/views/receipt_layouts.php?kind=collection&a=test');
Checks::true('...its test page prints the layout with sample entries', $admin->status === 200 && substr_count($admin->body, 'data-f="account"') === 2 && str_contains($admin->body, '@page'));
if ($rF !== '') {
    $admin->get('water/views/collection_receipt.php?receipt_no=' . rawurlencode($rF) . '&type=preprinted');
    Checks::true('the water bill / collection receipt on its pre-printed form: only the entries, two copies', $admin->status === 200 && substr_count($admin->body, 'data-f="account"') === 2 && str_contains($admin->body, 'wrl-page'), $admin->url);
    $admin->get('water/views/generate_official_receipt.php?receipt_no=' . rawurlencode($rF) . '&type=preprinted');
    Checks::true('the official receipt on its pre-printed form', $admin->status === 200 && str_contains($admin->body, 'is-preprinted') && str_contains($admin->body, 'data-f="total_sales"'), $admin->url);
}
$admin->post('water/views/receipt_layouts.php?kind=collection', ['csrf_token' => $admin->csrf(), 'kind' => 'collection', 'action' => 'reset']);
Checks::eq('...put back to the default layout', '', (string)$q("SELECT setting_value FROM settings WHERE setting_key = 'water_receipt_layout_collection'"));

// ======================================================================================== v47
Checks::section('v47 Billing Adjustments: Edit opens the bill itself; the Individual Statement of Account');
$cls = ['class_id' => $classId];   // the class of the water forms ($org is an account row since 7.8)
$in = static fn(int $days): string => date('Y-m-d', strtotime($today . ' ' . ($days >= 0 ? '+' : '') . $days . ' days'));
$money2 = static fn($v): string => number_format((float)$v, 2, '.', '');
// an unpaid one-time bill (a service fee) of account E, nothing collected on it
$admin->post('water/process/addOneTimeBill.php', ['csrf_token' => $admin->csrf(), 'member_id' => $E, 'bill_code_id' => $memCode, 'quantity' => 1, 'total_amount' => 55,
    'bill_date' => $today, 'due_date' => $in(5)] + $cls);
$ob = (int)$q('SELECT bill_id FROM one_time_bills WHERE member_id = :m ORDER BY bill_id DESC LIMIT 1', ['m' => $E]);
Checks::true('an unpaid one-time bill to correct (55.00, due in 5 days)', $ob > 0 && $money($q('SELECT total_amount FROM one_time_bills WHERE bill_id = :b', ['b' => $ob])) === 55.0);
$admin->get('water/views/billing_adjustments.php?type=one_time');
Checks::true('Billing Adjustments: Edit opens the bill (a link to the bill), the small Edit Uncollected Bill box is gone',
    $admin->status === 200 && str_contains($admin->body, 'billing_adjustments.php?edit=one_time-' . $ob) && !str_contains($admin->body, 'Edit Uncollected Bill'));
$admin->get('water/views/billing_adjustments.php?edit=one_time-' . $ob);
$acctE = $row('SELECT account_number, lastname FROM water_accounts WHERE pkey = :p', ['p' => $E]);
$b = $admin->body;
Checks::true('the editor shows the bill itself: its kind and invoice number, billed to, the bill code, the amount, the totals',
    $admin->status === 200 && str_contains($b, 'One-Time Bill') && str_contains($b, 'Invoice No.') && str_contains($b, 'Billed to') && str_contains($b, 'MEMBERSHIP FEE')
    && str_contains($b, 'Total amount') && str_contains($b, 'Balance due') && str_contains($b, 'Account no. ' . htmlspecialchars((string)$acctE['account_number'])), $admin->url);
Checks::true('...its correctable fields in the bill (the amount, the due date), the class and the reason beside it',
    str_contains($b, 'id="beAmount"') && str_contains($b, 'id="beDue"') && str_contains($b, 'name="class_id"') && str_contains($b, 'name="reason"') && str_contains($b, 'Save correction'));
// the preview: computed by the save's own functions, nothing written
$admin->post('water/process/previewBillCorrection.php', ['bill' => 'one_time-' . $ob, 'total_amount' => '60.00']);
Checks::true('the preview without the form token is refused (403)', $admin->status === 403 && ($admin->json['ok'] ?? true) === false, (string)$admin->status);
$admin->post('water/process/previewBillCorrection.php', ['csrf_token' => $admin->csrf(), 'bill' => 'one_time-' . $ob, 'total_amount' => '65.50', 'due_date' => $in(10)]);
$pb = $admin->json['bill'] ?? [];
Checks::true('preview: 65.50 due in 10 days, term 10 days, up by 10.50; the bill is not changed',
    ($admin->json['ok'] ?? false) === true && $money2($pb['total'] ?? 0) === '65.50' && (int)($pb['term_days'] ?? -1) === 10 && $money2($pb['change'] ?? 0) === '10.50'
    && $money($q('SELECT total_amount FROM one_time_bills WHERE bill_id = :b', ['b' => $ob])) === 55.0, json_encode($admin->json));
$admin->post('water/process/previewBillCorrection.php', ['csrf_token' => $admin->csrf(), 'bill' => 'one_time-' . $ob, 'total_amount' => '65.50', 'due_date' => $in(-3)]);
Checks::true('preview: a due date before the bill date is refused, with the reason', ($admin->json['ok'] ?? true) === false && str_contains((string)($admin->json['error'] ?? ''), 'before the bill date'), json_encode($admin->json));
$admin->post('water/process/previewBillCorrection.php', ['csrf_token' => $admin->csrf(), 'bill' => 'water-' . $readings[$A]['id'], 'pres_reading' => 40]);
Checks::true('preview of a collected water bill is refused (a debit or credit memo instead)', ($admin->json['ok'] ?? true) === false && ($admin->json['error'] ?? '') !== '', json_encode($admin->json));
// a refused save goes back to the bill with what was typed; then it is saved
$admin->post('water/process/billLifecycleProcess.php', ['csrf_token' => $admin->csrf(), 'action' => 'edit', 'return' => 'editor', 'bill_type' => 'one_time', 'bill_id' => $ob,
    'total_amount' => '65.50', 'due_date' => $in(10), 'reason' => 'no'] + $cls);
Checks::true('a refused correction (a reason too short) goes back to the bill with the message and the amount typed',
    str_contains($admin->url, 'billing_adjustments.php?edit=one_time-' . $ob) && str_contains($admin->body, 'at least 5 characters') && str_contains($admin->body, 'value="65.50"'), $admin->url);
Checks::eq('...and nothing is saved', 55.0, $money($q('SELECT total_amount FROM one_time_bills WHERE bill_id = :b', ['b' => $ob])));
$admin->post('water/process/billLifecycleProcess.php', ['csrf_token' => $admin->csrf(), 'action' => 'edit', 'return' => 'editor', 'bill_type' => 'one_time', 'bill_id' => $ob,
    'total_amount' => '65.50', 'due_date' => $in(10), 'reason' => 'Fee raised to 65.50 (e2e v47)'] + $cls);
Checks::true('the correction is saved from the bill: back on Billing Adjustments with the message', str_contains($admin->url, 'billing_adjustments.php') && !str_contains($admin->url, 'edit=') && str_contains($admin->body, 'corrected'), $admin->url);
$obRow = $row('SELECT total_amount, due_date, term_days, bill_edit_count FROM one_time_bills WHERE bill_id = :b', ['b' => $ob]);
Checks::true('the bill: 65.50, due in 10 days, term (days) 10, corrected once', $money2($obRow['total_amount']) === '65.50' && $obRow['due_date'] === $in(10)
    && (int)$obRow['term_days'] === 10 && (int)$obRow['bill_edit_count'] === 1, json_encode($obRow));
$wObR = WaterPosting::byEvent(WaterPosting::eventKey('one_time', $ob, 1));
Checks::true('its invoice is replaced by the correction "-R1" for 65.50', $wObR && $wObR['status'] === 'posted' && $money2($q('SELECT total FROM transactions WHERE id = :t', ['t' => $wObR['txn_id']])) === '65.50', $wObR['status'] ?? 'missing');
// a water bill not collected: its readings and tariff lines, the amount recomputed for another reading
$wOpen = $row("SELECT br.reading_id, br.member_id, br.prev_reading, br.pres_reading FROM bill_readings br WHERE br.is_billed = 1 AND COALESCE(br.amount_paid, 0) = 0
    AND COALESCE(br.bill_lifecycle_status, 'active') = 'active' AND NOT EXISTS (SELECT 1 FROM payment_transaction_items i WHERE i.source_type = 'water' AND i.source_id = br.reading_id)
    ORDER BY br.reading_id LIMIT 1");
if ($wOpen) {
    $admin->get('water/views/billing_adjustments.php?edit=water-' . (int)$wOpen['reading_id']);
    Checks::true('a water bill opens with its meter reading, consumption and the tariff lines', $admin->status === 200 && str_contains($admin->body, 'Meter reading')
        && str_contains($admin->body, 'Previous reading') && str_contains($admin->body, 'name="pres_reading"') && str_contains($admin->body, 'Consumption range') && str_contains($admin->body, 'Amount due'));
    $newPres = (int)$wOpen['pres_reading'] + 3;
    $admin->post('water/process/previewBillCorrection.php', ['csrf_token' => $admin->csrf(), 'bill' => 'water-' . (int)$wOpen['reading_id'], 'pres_reading' => $newPres]);
    $expect = compute_water_billing_details(DB::pdo(), (int)$wOpen['member_id'], $newPres - (int)$wOpen['prev_reading'], true)['amount_due'];
    Checks::true('preview: 3 m3 more is priced at the tariff, as the save prices it', ($admin->json['ok'] ?? false) === true && $money2($admin->json['bill']['total'] ?? -1) === $money2($expect)
        && (int)($admin->json['bill']['water']['consumption'] ?? -1) === $newPres - (int)$wOpen['prev_reading'], json_encode($admin->json));
    $admin->post('water/process/previewBillCorrection.php', ['csrf_token' => $admin->csrf(), 'bill' => 'water-' . (int)$wOpen['reading_id'], 'pres_reading' => (int)$wOpen['prev_reading'] - 1]);
    Checks::true('preview: a present reading below the previous one is refused', ($admin->json['ok'] ?? true) === false
        && (str_contains((string)($admin->json['error'] ?? ''), 'lower than the previous reading') || str_contains((string)($admin->json['error'] ?? ''), 'cannot be negative')), json_encode($admin->json));
}
// an installment contract: its schedule, a corrected total spread again over the same due dates
$admin->post('water/process/addInstallmentBill.php', ['csrf_token' => $admin->csrf(), 'member_id' => $E, 'bill_code_id' => $memCode, 'quantity' => 1, 'total_amount' => 600, 'term' => 3,
    'payment_mode' => 'monthly', 'amortization_type' => 'fixed', 'start_date' => $today] + $cls);
$inst2 = (int)$q('SELECT installment_id FROM installment_bills WHERE member_id = :m ORDER BY installment_id DESC LIMIT 1', ['m' => $E]);
$admin->get('water/views/billing_adjustments.php?edit=installment-' . $inst2);
Checks::true('an installment bill opens with its payment schedule', $admin->status === 200 && str_contains($admin->body, 'Payment schedule') && preg_match_all('/<td class="num mono" data-i="\d+">/', $admin->body) === 3);
$admin->post('water/process/previewBillCorrection.php', ['csrf_token' => $admin->csrf(), 'bill' => 'installment-' . $inst2, 'total_amount' => '601']);
Checks::eq('preview: 601.00 spread again over the three due dates', '200.34, 200.33, 200.33',
    implode(', ', array_map(fn($x) => $money2($x['amount']), $admin->json['bill']['schedule'] ?? [])));
// the rights: Billing Adjustments and a Super Administrator
$cas->get('water/views/billing_adjustments.php?edit=one_time-' . $ob);
Checks::true('a cashier cannot open the bill editor', $cas->status === 403 || !str_contains($cas->body, 'id="beForm"'), (string)$cas->status);
$cas->post('water/process/previewBillCorrection.php', ['csrf_token' => $cas->csrf(), 'bill' => 'one_time-' . $ob, 'total_amount' => '1']);
Checks::true('...nor ask for a corrected bill', $cas->status === 403 || ($cas->json['ok'] ?? false) === false, (string)$cas->status);
Checks::eq('trial balance after the corrections: debits = credits', 0.0, $tbDiff());

// the Individual Statement of Account: a second billing period, so an account has unpaid water bills in two periods
$x = $row("SELECT br.member_id, br.pres_reading FROM bill_readings br WHERE br.period_id = :p AND br.is_billed = 1 AND br.amount_due > COALESCE(br.amount_paid, 0)
    AND COALESCE(br.bill_lifecycle_status, 'active') = 'active' ORDER BY br.reading_id LIMIT 1", ['p' => $pid]);
$pEnd = substr((string)$period['end_date'], 0, 10);
$p2Start = date('Y-m-d', strtotime($pEnd . ' +1 day'));
$p2End = min(date('Y-m-d', strtotime($p2Start . ' +1 month -1 day')), $in(-1));
$admin->post('water/process/addBillPeriod.php', ['csrf_token' => $admin->csrf(), 'date_from' => $p2Start, 'date_to' => $p2End, 'duration' => 1]);
$pid2 = (int)$q("SELECT period_id FROM bill_periods WHERE period_id <> :p AND status = 'open' AND DATE(start_date) = :s ORDER BY period_id DESC LIMIT 1", ['p' => $pid, 's' => $p2Start]);
Checks::true('a second billing period after the first (Bill Period Management)', $pid2 > 0 && $x !== null, $p2Start . ' to ' . $p2End);
$X = (int)($x['member_id'] ?? 0);
$admin->postJson('water/process/saveIndividualReading.php', ['period_id' => $pid2, 'member_id' => $X, 'pres_reading' => (int)($x['pres_reading'] ?? 0) + 7]);
$admin->postJson('water/process/postBilling.php', ['action' => 'post', 'period_id' => $pid2, 'filter' => 'water', 'billing_date' => $today, 'term_days' => 15] + $cls);
Checks::eq('the account has unpaid water bills in two billing periods', 2, (int)$q("SELECT COUNT(DISTINCT period_id) FROM bill_readings WHERE member_id = :m AND is_billed = 1
    AND amount_due > COALESCE(amount_paid, 0) AND COALESCE(bill_lifecycle_status, 'active') = 'active'", ['m' => $X]));
$admin->get('water/views/generate_soa.php');
Checks::true('Generate SOA / Post Billing: the Individual Statement of Account tab', $admin->status === 200 && str_contains($admin->body, 'id="tabBtnIndividual"') && str_contains($admin->body, 'id="tab-individual"'));
$admin->get('water/views/generate_soa.php?tab=individual');
Checks::true('...?tab=individual opens on it', (bool)preg_match('/id="tab-individual" class="tab-pane active"/', $admin->body));
$admin->get('water/views/generate_soa.php?view=soa&tab=individual');
Checks::true('...and from Reports (Generate Statement of Account), beside the statement of one billing period', $admin->status === 200 && str_contains($admin->body, 'Statement by Billing Period')
    && (bool)preg_match('/id="tab-individual" class="tab-pane active"/', $admin->body));
$soa = 'water/process/getIndividualSoaData.php?context=generate_soa&';
$xAcct = $row('SELECT account_number, lastname FROM water_accounts WHERE pkey = :p', ['p' => $X]);
$admin->get($soa . 'action=search&kind=all&q=' . rawurlencode((string)$xAcct['account_number']));
$hit = array_values(array_filter($admin->json['accounts'] ?? [], fn($a) => (int)$a['id'] === $X));
Checks::true('the member or customer is found by the account number, with the unpaid invoices counted', $hit !== [] && (int)$hit[0]['open_count'] >= 2 && in_array($hit[0]['kind'], ['Member', 'Customer'], true), json_encode($hit));
$admin->get($soa . 'action=search&kind=all&q=' . rawurlencode((string)$xAcct['lastname']));
Checks::true('...and by the last name', (bool)array_filter($admin->json['accounts'] ?? [], fn($a) => (int)$a['id'] === $X));
$admin->get($soa . 'action=invoices&account_id=' . $X . '&statement_date=' . $today);
$xInv = $admin->json['invoices'] ?? [];
$got = array_map(fn($i) => $i['key'], array_values(array_filter($xInv, fn($i) => $i['type'] === 'water')));
$want = array_map(fn($r) => 'water:' . $r, DB::column("SELECT reading_id FROM bill_readings WHERE member_id = :m AND is_billed = 1 AND amount_due > COALESCE(amount_paid, 0)
    AND COALESCE(bill_lifecycle_status, 'active') <> 'deleted' ORDER BY reading_id", ['m' => $X]));
sort($got);
sort($want);
Checks::eq('every unpaid water bill is offered, whatever its billing period', implode(',', $want), implode(',', $got));
$dbOpen = $money($q("SELECT COALESCE(SUM(amount_due - COALESCE(amount_paid, 0)), 0) FROM bill_readings WHERE member_id = :m AND is_billed = 1 AND amount_due > COALESCE(amount_paid, 0) AND COALESCE(bill_lifecycle_status, 'active') <> 'deleted'", ['m' => $X])
    + $q("SELECT COALESCE(SUM(total_amount - COALESCE(amount_paid, 0)), 0) FROM one_time_bills WHERE member_id = :m AND status NOT IN ('cancelled', 'deleted') AND COALESCE(bill_lifecycle_status, 'active') <> 'deleted' AND total_amount > COALESCE(amount_paid, 0)", ['m' => $X])
    + $q("SELECT COALESCE(SUM(s.amount - COALESCE(s.amount_paid, 0)), 0) FROM installment_schedules s JOIN installment_bills b ON b.installment_id = s.installment_id WHERE b.member_id = :m AND b.status NOT IN ('cancelled', 'deleted') AND COALESCE(b.bill_lifecycle_status, 'active') <> 'deleted' AND s.amount > COALESCE(s.amount_paid, 0)", ['m' => $X])
    + $q("SELECT COALESCE(SUM(total_amount - COALESCE(amount_paid, 0)), 0) FROM recurring_bills WHERE member_id = :m AND status NOT IN ('cancelled', 'deleted') AND COALESCE(bill_lifecycle_status, 'active') <> 'deleted' AND total_amount > COALESCE(amount_paid, 0)", ['m' => $X]));
$listed = $money(array_sum(array_map(fn($i) => (float)$i['balance'], array_filter($xInv, fn($i) => $i['type'] !== 'sharecap'))));
Checks::eq('...with the balances of the bills on file (water, one-time, installment, recurring)', $dbOpen, $listed);
$admin->get($soa . 'action=invoices&account_id=' . $E . '&statement_date=' . $today);
$eBal = array_column($admin->json['invoices'] ?? [], 'balance', 'key');
Checks::true('account E: the corrected one-time bill (65.50) and every unpaid amortization of its installment contracts', ($eBal['one_time:' . $ob] ?? '') === '65.50'
    && count(array_filter(array_keys($eBal), fn($k) => str_starts_with((string)$k, 'installment:'))) >= 3, json_encode($eBal));
$admin->get($soa . 'action=invoices&account_id=' . $X . '&statement_date=' . date('Y-m-d', strtotime($pEnd . ' -60 days')));
Checks::eq('a statement dated before the bills lists none of them', 0, count(array_filter($admin->json['invoices'] ?? [], fn($i) => $i['type'] === 'water')));
$admin->get($soa . 'action=invoices&account_id=' . $X . '&statement_date=2026-02-30');
Checks::true('a statement date that is not a date is refused', ($admin->json['ok'] ?? true) === false, json_encode($admin->json));
// the statement: only the invoices chosen, as they stand; another account's bill and a paid one are left off
$pickW = $got[0] ?? 'water:0';
$admin->get($soa . 'action=statement&account_id=' . $X . '&statement_date=' . $today . '&items=' . rawurlencode($pickW . ',water:999999999,one_time:' . $ob));
$st = $admin->json;
Checks::true('the statement lists only the invoice chosen of the account; one not unpaid and another account\'s bill are left off and named',
    ($st['ok'] ?? false) === true && count($st['invoices'] ?? []) === 1 && ($st['invoices'][0]['key'] ?? '') === $pickW
    && ($st['dropped'] ?? []) === ['water:999999999', 'one_time:' . $ob], json_encode(['n' => count($st['invoices'] ?? []), 'dropped' => $st['dropped'] ?? null]));
Checks::true('...its total is that invoice\'s balance; the cooperative, the account and the General Manager are on it', ($st['totals']['total'] ?? '-') === ($st['invoices'][0]['balance'] ?? '')
    && ($st['account']['id'] ?? 0) === $X && ($st['company']['name'] ?? '') !== '' && isset($st['signatories']['general_manager']));
$admin->get($soa . 'action=statement&account_id=' . $X . '&statement_date=' . $today . '&items=');
Checks::true('a statement with no invoice chosen is refused', ($admin->json['ok'] ?? true) === false);
$admin->get('water/process/getSoaData.php?member_id=' . $X . '&period_id=' . $pid2 . '&statement_date=' . $today);
Checks::eq('the statement of one billing period still lists that period\'s water bill', 1, count($admin->json['current']['Water Bill'] ?? []));
// an installment amortization not yet due is listed, not ticked at first (an upcoming due)
$admin->get($soa . 'action=invoices&account_id=' . $E . '&statement_date=' . $today);
$upc = array_values(array_filter($admin->json['invoices'] ?? [], fn($i) => $i['type'] === 'installment' && $i['status'] === 'current'));
$due = array_values(array_filter($admin->json['invoices'] ?? [], fn($i) => $i['type'] !== 'installment' || $i['status'] !== 'current'));
Checks::true('an installment amortization not yet due is listed but not ticked at first; the others are', $upc !== [] && !array_filter($upc, fn($i) => $i['preselect'])
    && !array_filter($due, fn($i) => !$i['preselect']), json_encode(array_map(fn($i) => [$i['key'], $i['status'], $i['preselect']], $admin->json['invoices'] ?? [])));
// a member or customer with two water-service accounts: one name, both accounts, or one of them
$pp = $row('SELECT party_id, MIN(pkey) AS a1, MAX(pkey) AS a2 FROM water_accounts WHERE party_id IS NOT NULL GROUP BY party_id HAVING COUNT(*) > 1 ORDER BY party_id LIMIT 1');
if ($pp) {
    $a2no = (string)$q('SELECT account_number FROM water_accounts WHERE pkey = :p', ['p' => $pp['a2']]);
    $admin->get($soa . 'action=search&kind=all&q=' . rawurlencode($a2no));
    $person = array_values(array_filter($admin->json['accounts'] ?? [], fn($a) => in_array((int)$pp['a2'], array_map(fn($x) => (int)$x['id'], $a['accounts']), true)));
    Checks::true('a member or customer with two accounts is one name with both accounts', count($person) === 1 && count($person[0]['accounts']) === 2 && (int)$person[0]['id'] === (int)$pp['a1'], json_encode($person));
    $admin->get($soa . 'action=invoices&account_id=' . $pp['a2'] . '&scope=all&statement_date=' . $today);
    $all = $admin->json;
    Checks::true('...all accounts: the invoices of both, each with its account', count($all['scope'] ?? []) === 2
        && !array_filter($all['invoices'] ?? [], fn($i) => !in_array((int)$i['account_id'], [(int)$pp['a1'], (int)$pp['a2']], true)), json_encode($all['scope'] ?? null));
    $keys = array_column($all['invoices'] ?? [], 'key');
    Checks::eq('...no invoice twice (a share capital bill on the first account only)', count($keys), count(array_unique($keys)));
    $admin->get($soa . 'action=invoices&account_id=' . $pp['a1'] . '&scope=' . $pp['a2'] . '&statement_date=' . $today);
    Checks::true('...one account: its invoices only', ($admin->json['ok'] ?? false) && count($admin->json['scope'] ?? []) === 1
        && !array_filter($admin->json['invoices'] ?? [], fn($i) => (int)$i['account_id'] !== (int)$pp['a2'] || $i['type'] === 'sharecap'), json_encode($admin->json['scope'] ?? $admin->json));
    $admin->get($soa . 'action=invoices&account_id=' . $pp['a1'] . '&scope=' . $E . '&statement_date=' . $today);
    Checks::true('...another person\'s account is refused', ($admin->json['ok'] ?? true) === false || (int)$E === (int)$pp['a1'] || (int)$E === (int)$pp['a2'], json_encode($admin->json));
}
// a recurring bill: the same due date keeps its term; a new one counts from the end of the service period
$rb = $row('SELECT bill_id, due_date, term_days, billing_period_end FROM recurring_bills WHERE member_id = :m ORDER BY bill_id DESC LIMIT 1', ['m' => $F]);
if ($rb) {
    $admin->post('water/process/previewBillCorrection.php', ['csrf_token' => $admin->csrf(), 'bill' => 'recurring-' . $rb['bill_id'], 'total_amount' => '80.00', 'due_date' => $rb['due_date']]);
    $rbPrev = $admin->json['bill'] ?? [];
    Checks::true('a recurring bill corrected with the same due date keeps its due date and term', ($admin->json['ok'] ?? false) === true
        && array_key_exists('due_date', $rbPrev) && $rbPrev['due_date'] === null && array_key_exists('term_days', $rbPrev) && $rbPrev['term_days'] === null, json_encode($admin->json));
    $nd = date('Y-m-d', strtotime($rb['due_date'] . ' +5 days'));
    $admin->post('water/process/previewBillCorrection.php', ['csrf_token' => $admin->csrf(), 'bill' => 'recurring-' . $rb['bill_id'], 'total_amount' => '80.00', 'due_date' => $nd]);
    Checks::eq('...a new due date: the term counts from the end of its service period', (int)((strtotime($nd) - strtotime(substr((string)$rb['billing_period_end'], 0, 10))) / 86400),
        (int)($admin->json['bill']['term_days'] ?? -1));
    $admin->post('water/process/previewBillCorrection.php', ['csrf_token' => $admin->csrf(), 'bill' => 'recurring-' . $rb['bill_id'], 'total_amount' => '80.00',
        'due_date' => date('Y-m-d', strtotime(substr((string)$rb['billing_period_end'], 0, 10) . ' -1 day'))]);
    Checks::true('...a due date before the end of the service period is refused', ($admin->json['ok'] ?? true) === false || substr((string)$rb['billing_period_end'], 0, 10) <= $today, json_encode($admin->json));
}
$cas->get($soa . 'action=search&kind=all&q=a');
Checks::true('a cashier (no Post Billing) cannot read the statement data', $cas->status === 403 || ($cas->json['ok'] ?? false) !== true, (string)$cas->status);

// ======================================================================================== v49
Checks::section('v49 Data Import Management and the Technical prerequisites step removed');
$admin->get('water/views/import_management.php');
Checks::eq('the Data Import Management page is gone', 404, $admin->status);
$admin->post('water/process/stageImportCsv.php', ['csrf_token' => $admin->csrf()]);
Checks::eq('...and its endpoints', 404, $admin->status);
$admin->get('water/views/settings.php');
Checks::true('Water Settings: no Data Import Management card', $admin->status === 200 && !str_contains($admin->body, 'Data Import Management'));
$admin->get('index.php?p=admin.page_access');
Checks::true('Page Access does not offer Data Import Management (its row stays in the database)', $admin->status === 200
    && str_contains($admin->body, 'Water Settings') && !str_contains($admin->body, 'Data Import Management'));
$admin->get('index.php?p=water.dashboard');
Checks::true('the menu has no Data Import Management', $admin->status === 200 && !str_contains($admin->body, 'import_management'));
$admin->get('water/process/getSetupReadiness.php?page=dashboard');
$keys = array_column($admin->json['steps'] ?? [], 'key');
Checks::true('the set-up guide: no Technical prerequisites step, the administrator first, 6 steps (no Billing readiness since v50)', ($admin->json['success'] ?? false) === true
    && !in_array('technical', $keys, true) && ($keys[0] ?? '') === 'administrator' && (int)($admin->json['total_steps'] ?? 0) === 6, implode(',', $keys));
Checks::true('...and nothing in it about storage/imports', !str_contains((string)json_encode($admin->json), 'storage/imports'));

Checks::section('v50 An open billing period is not a step of the set-up guide');
Checks::true('the guide has no Billing readiness step', !in_array('billing', $keys, true), implode(',', $keys));
$openPeriods = DB::column("SELECT period_id FROM bill_periods WHERE status = 'open'");
DB::run("UPDATE bill_periods SET status = 'closed' WHERE status = 'open'");
$admin->get('water/process/getSetupReadiness.php?page=reading_entry');
Checks::true('with no open period, the guide on Reading Entry is complete (nothing blocks the page)', ($admin->json['complete'] ?? false) === true
    && !str_contains((string)json_encode($admin->json), 'open billing period'), (string)json_encode($admin->json['current'] ?? null));
$admin->get('water/views/reading_entry.php');
Checks::true('Reading Entry says itself that no period is open', $admin->status === 200 && str_contains($admin->body, 'No open reading periods found'));
$closedPeriod = (int)$q('SELECT period_id FROM bill_periods ORDER BY period_id DESC LIMIT 1');
$admin->postJson('water/process/saveIndividualReading.php', ['period_id' => $closedPeriod, 'member_id' => $A, 'pres_reading' => 999999]);
Checks::true('a reading for a period that is not open is refused', ($admin->json['success'] ?? true) === false
    && str_contains((string)($admin->json['message'] ?? $admin->json['error'] ?? ''), 'closed'), $admin->status . ' ' . substr($admin->body, 0, 200));
if ($openPeriods) {
    DB::run("UPDATE bill_periods SET status = 'open' WHERE period_id IN (" . implode(',', array_map('intval', $openPeriods)) . ')');
}

// ======================================================================================== v51
Checks::section('v51 Billing List with its date filters empty, set and not a date (MySQL 8 error 1525)');
$admin->get('water/views/billing_list.php');
$allRows = substr_count($admin->body, '<tr><td>');
Checks::true('date filters empty: the register loads', $admin->status === 200 && !str_contains($admin->body, 'Unable to load') && $allRows > 0, (string)$allRows);
$admin->get('water/views/billing_list.php?from=&to=&type=all&status=all&q=');
Checks::true('...also sent empty by the Apply button', $admin->status === 200 && !str_contains($admin->body, 'Unable to load') && substr_count($admin->body, '<tr><td>') === $allRows);
$admin->get('water/views/billing_list.php?from=2000-01-01&to=2000-01-31');
Checks::true('a date range with no bill: none listed, no error', $admin->status === 200 && !str_contains($admin->body, 'Unable to load') && str_contains($admin->body, 'No billing records matched.'));
$admin->get('water/views/billing_list.php?from=' . date('Y-m-d', strtotime('-5 years')) . '&to=' . date('Y-m-d', strtotime('+1 year')));
Checks::true('a date range around every bill: all listed', $admin->status === 200 && substr_count($admin->body, '<tr><td>') === $allRows);
$admin->get('water/views/billing_list.php?from=not-a-date&to=2026-02-31');
Checks::true('a "date" that is not one is ignored, not an error', $admin->status === 200 && !str_contains($admin->body, 'Unable to load') && substr_count($admin->body, '<tr><td>') === $allRows);

// ======================================================================================== period close
Checks::section('7.4c Period close');
$admin->post('water/process/editBillPeriod.php', ['csrf_token' => $admin->csrf(), 'period_id' => $pid, 'action' => 'close', 'status' => 'closed']);
$st = (string)$q('SELECT status FROM bill_periods WHERE period_id = :p', ['p' => $pid]);
Checks::eq('the billing period is closed', 'closed', strtolower($st));
$admin->postJson('water/process/saveIndividualReading.php', ['period_id' => $pid, 'member_id' => $accts[5], 'pres_reading' => 99]);
Checks::true('a reading in a closed period is refused', ($admin->json['success'] ?? true) === false, $admin->json['message'] ?? '');

// ======================================================================================== final ledger integrity
Checks::section('Ledger integrity at the end');
Checks::eq('trial balance: debits = credits', 0.0, $tbDiff());
$unbalanced = (int)$q('SELECT COUNT(*) FROM (SELECT txn_id FROM journal_lines GROUP BY txn_id HAVING ABS(SUM(debit - credit)) >= 0.005) x');
Checks::eq('every document balances', 0, $unbalanced);
$dupEvents = (int)$q('SELECT COUNT(*) FROM (SELECT txn_id FROM water_postings WHERE txn_id IS NOT NULL AND txn_type <> \'void\' GROUP BY txn_id HAVING COUNT(*) > 1) x');
Checks::eq('no ledger document belongs to two water events', 0, $dupEvents);
$arCtl = $money($q("SELECT COALESCE(SUM(j.debit - j.credit), 0) FROM journal_lines j JOIN accounts a ON a.id = j.account_id WHERE a.code = '11784'"));
$arSub = 0.0;
foreach (DB::column("SELECT DISTINCT w.txn_id FROM water_postings w JOIN transactions t ON t.id = w.txn_id WHERE t.txn_type IN ('invoice', 'finance_charge') AND t.is_voided = 0 AND t.account_id = (SELECT id FROM accounts WHERE code = '11784')") as $tid) {
    $arSub += $openOf((int)$tid);
}
Checks::eq('A/R control account = open water invoices (subledger)', $money($arSub), $arCtl);
$waterOpen = 0.0;
foreach (DB::column('SELECT DISTINCT pkey FROM water_accounts WHERE pkey IN (' . implode(',', $accts) . ')') as $a) {
    $waterOpen += WaterAccounts::openBalance((int)$a)['total'];
}
Checks::eq('water records open balance = A/R subledger for the accounts used', $money($waterOpen), $money($arSub));

// ======================================================================================== report
$secs = round(microtime(true) - $started, 1);
$md = Checks::markdown('Water module end-to-end acceptance tests (HTTP)', [
    'Run' => date('Y-m-d H:i:s') . " ($secs s)", 'Server' => $base, 'Database' => DB::databaseName() . ' (fresh install + the former water database migrated with tools/migrate_water.php)',
    'Environment' => 'PHP ' . PHP_VERSION . ', ' . DB::value('SELECT VERSION()'),
]);
$out = $opts['out'] ?? (__DIR__ . '/RESULTS_E2E.md');
file_put_contents($out, $md);
$total = count(Checks::$rows);
echo "\n" . ($total - Checks::$fail) . " passed, " . Checks::$fail . " failed, $total checks ({$secs}s). Written to $out\n";
exit(Checks::$fail ? 1 : 0);
