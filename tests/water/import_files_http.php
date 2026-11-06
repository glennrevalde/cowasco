<?php
declare(strict_types=1);

/**
 * Data Import Management end to end, over HTTP the way the page drives it: the former water program's Account Master
 * and Billing Master files are uploaded, staged in chunks, imported (members; water bills, recurring charges,
 * collections, opening balances) and the result checked in the database: every account has a party, the ledger
 * balances, the water receivables equal the ledger, an imported open bill is collected at the cashier and the next
 * meter reading continues from the imported history.
 *
 *   DB_NAME=<db> php tests/water/import_files_http.php --base=http://127.0.0.1:8131 --accounts=<Account Master.csv>
 *        --billing=<Billing Master.csv> [--password=<admin password>] [--out=tests/water/RESULTS_IMPORT_FILES.md]
 *
 * The database is a new installation with the water starter set-up (`php tools/install.php --water-starter`, or
 * database/accounting_system_clean.sql imported); the web server serves this folder on the same database.
 */
if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}
$opts = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z]+)=(.*)$/', $a, $m)) {
        $opts[$m[1]] = $m[2];
    }
}
$base = rtrim((string)($opts['base'] ?? 'http://127.0.0.1:8131'), '/');
$accountsFile = (string)($opts['accounts'] ?? '');
$billingFile = (string)($opts['billing'] ?? '');
if (!is_file($accountsFile) || !is_file($billingFile)) {
    exit("Give --accounts=<Account Master CSV> and --billing=<Billing Master CSV>.\n");
}
require __DIR__ . '/../../app/bootstrap.php';
require __DIR__ . '/e2e_lib.php';
require_once __DIR__ . '/../../water/database/config.php';
require_once __DIR__ . '/../../water/common/dataImportHelpers.php';
$_SESSION = [];
Tenant::bootCli();
$started = microtime(true);
$q = fn(string $sql, array $p = []) => DB::value($sql, $p);
$money = fn($v) => round((float)$v, 2);

$PASS = (string)($opts['password'] ?? 'Import#Test2026');
$admin = new Browser($base, 'import_admin');
Checks::section('0. Set-up');
Checks::true('admin signs in', $admin->login('admin', 'Admin@12345', $PASS) || $admin->login('admin', $PASS), $admin->url);
$admin->get('water/views/import_management.php');
Checks::true('Data Import Management says the database is compatible', $admin->status === 200 && !str_contains($admin->body, 'not compatible with this importer'),
    preg_match('/not compatible with this importer.<\/strong>\s*(.*?)<\/div>/s', $admin->body, $m) ? trim($m[1]) : (string)$admin->status);
Checks::true('the profile of the former program\'s files is offered and preselected', (bool)preg_match('/<option value="\d+" data-options="[^"]*" selected>Former water program files/', $admin->body));
// what an administrator sets once before the first water transaction (the program says so when it is missing)
if (!(int)$q("SELECT COUNT(*) FROM companyofficers WHERE COALESCE(isdeleted, 0) = 0 AND TRIM(name) <> ''")) {
    $admin->post('water/process/saveInstitutionConfiguration.php', ['csrf_token' => $admin->csrf(), 'officers' => ['manager' => 'Water Manager', 'general_manager' => 'General Manager']]);
}
// the class of the imported bills and collections (Chart of Accounts > Class List): required by the import
$importClass = (int)$q('SELECT id FROM classes WHERE is_active = 1 ORDER BY id LIMIT 1')
    ?: (int)DB::insert('classes', ['name' => 'Water Utility', 'description' => 'Water service (import test)', 'sort_order' => 10, 'created_by' => (int)$q("SELECT id FROM users WHERE username = 'admin'")]);

/** multipart upload with the admin's session (the page's upload form) */
$upload = function (string $file, array $fields) use ($admin, $base): array {
    $jar = (new ReflectionProperty(Browser::class, 'jar'));
    $jar->setAccessible(true);
    $ch = curl_init($base . '/water/process/stageImportCsv.php');
    $post = $fields + ['csrf_token' => $admin->csrf(), 'csv_file' => new CURLFile($file, 'text/csv', basename($file))];
    $flat = [];
    foreach ($post as $k => $v) {
        if (is_array($v)) {
            foreach ($v as $k2 => $v2) {
                $flat[$k . '[' . $k2 . ']'] = (string)$v2;
            }
        } else {
            $flat[$k] = $v;
        }
    }
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $flat, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_COOKIEJAR => $jar->getValue($admin), CURLOPT_COOKIEFILE => $jar->getValue($admin), CURLOPT_TIMEOUT => 600]);
    $body = (string)curl_exec($ch);
    $url = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    return [preg_match('/batch_id=(\d+)/', $url, $m) ? (int)$m[1] : 0, $body];
};
/** the profile's options as the page's script fills the upload form with them */
$profile = json_decode((string)$q("SELECT options_json FROM data_import_mapping_profiles WHERE profile_name = 'Former water program files' ORDER BY version DESC LIMIT 1"), true) ?: [];
$form = ['mapping_profile_id' => (int)$q("SELECT profile_id FROM data_import_mapping_profiles WHERE profile_name = 'Former water program files' ORDER BY version DESC LIMIT 1"),
    'stage_payments' => !empty($profile['stage_payments']) ? '1' : '0', 'address_mode' => $profile['address_mode'] ?? 'source'];
foreach (['A', 'B', 'C', 'D'] as $c) {
    $form['tariff_map'][$c] = ($profile['rate_map'][$c] ?? 0) . '|' . ($profile['pipe_map'][$c] ?? 0);
}
foreach (($profile['recurring_map'] ?? []) as $col => $planId) {
    $form['recurring_map'][$col] = $planId;
}
foreach (['default_city_id', 'default_zip', 'source_discount_id', 'senior_discount_id', 'default_class_id'] as $k) {
    $form[$k] = (string)($profile[$k] ?? 0);
}
if ((int)$form['default_class_id'] <= 0) {
    $form['default_class_id'] = (string)$importClass;
}
$stage = function (int $batchId) use ($admin): array {
    $n = 0;
    do {
        $admin->post('water/process/stageImportChunk.php', ['csrf_token' => $admin->csrf(), 'batch_id' => $batchId, 'action' => 'stage'], ['X-CSRF-Token: ' . $admin->csrf(), 'Accept: application/json']);
        $n++;
        if (($admin->json['ok'] ?? false) !== true) {
            return ['ok' => false, 'message' => $admin->json['message'] ?? substr($admin->body, 0, 300)];
        }
    } while (empty($admin->json['done']) && $n < 200);
    return ['ok' => true, 'chunks' => $n];
};
$run = function (int $batchId, string $mode, array $extra = []) use ($admin, $importClass): array {
    $extra += $mode === 'members' ? [] : ['class_id' => $importClass];
    $processed = 0;
    $failed = 0;
    $chunks = 0;
    do {
        $admin->post('water/process/runImportChunk.php', ['csrf_token' => $admin->csrf(), 'batch_id' => $batchId, 'mode' => $mode, 'ajax' => '1'] + $extra,
            ['X-CSRF-Token: ' . $admin->csrf(), 'Accept: application/json', 'X-Requested-With: XMLHttpRequest']);
        $chunks++;
        if (($admin->json['ok'] ?? false) !== true) {
            return ['ok' => false, 'message' => $admin->json['message'] ?? substr(strip_tags($admin->body), 0, 300), 'processed' => $processed, 'failed' => $failed, 'chunks' => $chunks];
        }
        $processed += (int)$admin->json['processed'];
        $failed += (int)$admin->json['failed'];
        $remaining = (int)$admin->json['remaining'];
        if ((int)$admin->json['processed'] === 0 && (int)$admin->json['failed'] === 0 && $remaining > 0) {
            return ['ok' => false, 'message' => 'no progress', 'processed' => $processed, 'failed' => $failed, 'chunks' => $chunks];
        }
    } while ($remaining > 0 && $chunks < 2000);
    return ['ok' => true, 'processed' => $processed, 'failed' => $failed, 'chunks' => $chunks];
};
$batchRow = fn(int $id) => DB::row('SELECT * FROM data_import_batches WHERE batch_id = :b', ['b' => $id]);
/** what the source file says (records, and how many have a given value in a column) */
$fileStats = function (string $file, array $wanted): array {
    $h = fopen($file, 'rb');
    $head = array_map(fn($x) => trim((string)$x, "\xEF\xBB\xBF \t"), fgetcsv($h, 0, ',', '"', '\\'));
    $n = 0;
    $hits = array_fill_keys(array_keys($wanted), 0);
    while (($r = fgetcsv($h, 0, ',', '"', '\\')) !== false) {
        if ($r === [null]) continue;
        $n++;
        $row = @array_combine($head, $r) ?: [];
        foreach ($wanted as $k => [$col, $val]) {
            if (strtoupper(trim((string)($row[$col] ?? ''))) === $val) $hits[$k]++;
        }
    }
    fclose($h);
    return ['rows' => $n] + $hits;
};
$accStats = $fileStats($accountsFile, ['rate_a' => ['Rate Code', 'A'], 'senior' => ['SeniorMaster', 'TRUE']]);
$billStats = $fileStats($billingFile, []);
$policy = (string)($opts['policy'] ?? 'opening_balance');
$codes = function (int $batchId, string $statusCol, string $status) {
    $out = [];
    foreach (DB::column("SELECT issues_json FROM data_import_rows WHERE batch_id = :b AND $statusCol = :s", ['b' => $batchId, 's' => $status]) as $j) {
        foreach (json_decode((string)$j, true) ?: [] as $i) {
            if (in_array($i['severity'] ?? '', ['review', 'error'], true)) {
                $out[$i['code']] = ($out[$i['code']] ?? 0) + 1;
            }
        }
    }
    arsort($out);
    return $out;
};

// ======================================================================================== Account Master
Checks::section('1. Account Master');
$accountsBefore = (int)$q('SELECT COUNT(*) FROM water_accounts');
$membersBefore = (int)$q('SELECT COUNT(*) FROM members');
[$batchA, $body] = $upload($accountsFile, $form);
Checks::true('the Account Master is accepted (batch created)', $batchA > 0, preg_match('/Action could not be completed.<\/strong>(.*?)<\/div>/s', $body, $m) ? trim(strip_tags($m[1])) : 'no batch');
$st = $stage($batchA);
Checks::true('staged in chunks without an error', $st['ok'], $st['message'] ?? ($st['chunks'] . ' chunk(s)'));
$b = $batchRow($batchA);
Checks::eq('detected as the Account Master', 'account_master', (string)$b['import_type']);
Checks::eq('every row staged', $accStats['rows'], (int)$b['row_count']);
echo '   staged: ready ' . $b['ready_count'] . ', review ' . $b['review_count'] . ', error ' . $b['error_count'] . "\n";
$r = $run($batchA, 'members');
Checks::true('Import all ready members runs to the end', $r['ok'], ($r['message'] ?? '') . ' processed ' . $r['processed'] . ', failed ' . $r['failed'] . ' in ' . $r['chunks'] . ' chunk(s)');
$b = $batchRow($batchA);
Checks::eq('every ready row imported (none failed)', (int)$b['row_count'] - (int)$b['review_count'] - (int)$b['error_count'], (int)$b['imported_count']);
Checks::eq('water-service accounts made', $accountsBefore + (int)$b['imported_count'], (int)$q('SELECT COUNT(*) FROM water_accounts'));
Checks::eq('each imported account belongs to a party', 0, (int)$q("SELECT COUNT(*) FROM water_accounts WHERE party_id IS NULL"));
Checks::eq('...one party each (never merged by name)', (int)$q('SELECT COUNT(*) FROM water_accounts'), (int)$q('SELECT COUNT(DISTINCT party_id) FROM water_accounts'));
Checks::eq('nobody is made a cooperative member', $membersBefore, (int)$q('SELECT COUNT(*) FROM members'));
Checks::true('possible duplicate parties are queued for Identity Review', (int)$q("SELECT COUNT(*) FROM party_match_reviews WHERE status = 'open'") > 0, (string)$q("SELECT COUNT(*) FROM party_match_reviews WHERE status = 'open'"));
Checks::eq('rate code A (' . $accStats['rate_a'] . ' accounts) -> residential associate, 1/2 inch, as the former program did', $accStats['rate_a'], (int)$q('SELECT COUNT(*) FROM water_accounts WHERE rc_id = 2 AND pipe_size_id = 2'));
Checks::eq('senior citizens (SeniorMaster) enrolled in the Senior Citizen discount', $accStats['senior'], (int)$q("SELECT COUNT(*) FROM discounted_members d JOIN discounts x ON x.discount_id = d.discount_id WHERE x.discount_type = 'Senior Citizen'"));
Checks::eq('mobile numbers (cellno) on file as contact channels', (int)$q("SELECT COUNT(*) FROM data_import_rows WHERE batch_id = :b AND import_status = 'imported' AND normalized_json NOT LIKE '%\"phone\":\"\"%'", ['b' => $batchA]), (int)$q('SELECT COUNT(DISTINCT memberkey) FROM membercontactchannels'));
$sample = DB::row("SELECT w.*, p.display_name FROM water_accounts w JOIN parties p ON p.id = w.party_id WHERE w.account_number = '11002'");
Checks::eq('account 11002 imported with its name split "SARMAGO, ISRAEL"', 'SARMAGO|ISRAEL', ($sample['lastname'] ?? '') . '|' . ($sample['firstname'] ?? ''));
$admin->get('water/views/member_list.php?search=11002');
Checks::true('the account opens on Water Service Accounts', $admin->status === 200 && str_contains($admin->body, '11002'));
$st = $stage($batchA);
$r = $run($batchA, 'members');
Checks::eq('importing the Account Master again writes nothing', (int)$q('SELECT COUNT(*) FROM water_accounts'), $accountsBefore + (int)$batchRow($batchA)['imported_count']);

// ======================================================================================== Billing Master
Checks::section('2. Billing Master (ledger policy: ' . $policy . ')');
if (di_rule(DB::pdo(), 'gl_policy') !== $policy) {
    $admin->post('water/process/saveImportMappingRule.php', ['csrf_token' => $admin->csrf(), 'rule_key' => 'gl_policy', 'rule_value' => $policy, 'notes' => 'import test', 'batch_id' => 0]);
    Checks::eq('ledger policy set on Mapping Decisions', $policy, (string)$q("SELECT rule_value FROM data_import_mapping_rules WHERE rule_key = 'gl_policy'"));
}
[$batchB, $body] = $upload($billingFile, $form);
Checks::true('the Billing Master is accepted (batch created)', $batchB > 0, preg_match('/Action could not be completed.<\/strong>(.*?)<\/div>/s', $body, $m) ? trim(strip_tags($m[1])) : 'no batch');
$st = $stage($batchB);
Checks::true('staged in chunks without an error', $st['ok'], $st['message'] ?? ($st['chunks'] . ' chunk(s)'));
$b = $batchRow($batchB);
Checks::eq('detected as the Billing Master', 'billing_master', (string)$b['import_type']);
Checks::eq('every row staged', $billStats['rows'], (int)$b['row_count']);
printf("   staged: bills ready %d / review %d / error %d; recurring ready %d / review %d; collections ready %d / review %d\n", $b['ready_count'], $b['review_count'], $b['error_count'],
    $b['recurring_ready_count'], $b['recurring_review_count'], $b['payment_ready_count'], $b['payment_review_count']);
echo '   bill review/error codes: ' . json_encode($codes($batchB, 'import_status', 'review') + $codes($batchB, 'import_status', 'error')) . "\n";
echo '   recurring review codes: ' . json_encode($codes($batchB, 'recurring_status', 'review')) . "\n";
echo '   collection review codes: ' . json_encode(array_slice($codes($batchB, 'payment_status', 'review'), 0, 12, true)) . "\n";
Checks::eq('no configuration blocker (water bill accounts, tariffs, charge plans, collection accounts)', '[]', json_encode(array_column(di_batch_configuration_blockers(DB::pdo(), $batchB), 'code')));
$admin->get('water/views/import_management.php?batch_id=' . $batchB);
Checks::true('the batch page offers Import entire Billing Master' . ($policy === 'opening_balance' ? ' with the cut-over date' : ''), str_contains($admin->body, 'Import entire Billing Master') && ($policy !== 'opening_balance' || str_contains($admin->body, 'name="cutover_date"')));
$cutover = date('Y-m-d');
$t0 = microtime(true);
foreach (['bills' => 'water bills', 'recurring' => 'recurring charges', 'payments' => 'collections'] + ($policy === 'opening_balance' ? ['opening' => 'opening balances'] : []) as $mode => $label) {
    $r = $run($batchB, $mode, ['cutover_date' => $cutover]);
    Checks::true("Import $label runs to the end", $r['ok'] && $r['failed'] === 0, ($r['message'] ?? '') . ' processed ' . $r['processed'] . ', failed ' . $r['failed'] . ' in ' . $r['chunks'] . ' chunk(s)');
}
$secs = round(microtime(true) - $t0);
$b = $batchRow($batchB);
printf("   imported in %ds: bills %d, recurring %d, collections %d\n", $secs, $b['imported_count'], $b['recurring_imported_count'], $b['payment_imported_count']);
Checks::eq('every ready water bill imported', (int)$b['imported_count'], (int)$q("SELECT COUNT(*) FROM data_import_rows WHERE batch_id = :b AND import_status = 'imported'", ['b' => $batchB]));
Checks::eq('no row failed on import', 0, (int)$q("SELECT COUNT(*) FROM data_import_rows WHERE batch_id = :b AND issues_json LIKE '%IMPORT_FAILED%'", ['b' => $batchB]));
Checks::eq('water bills in the water records', (int)$b['imported_count'], (int)$q('SELECT COUNT(*) FROM bill_readings'));
Checks::eq('receipts in the water records', (int)$b['payment_imported_count'], (int)$q('SELECT COUNT(*) FROM payment_transactions'));
if ($policy === 'full_posting') {
    Checks::section('3. Full historical posting in the ledger');
    $bills = (int)$q('SELECT COUNT(*) FROM bill_readings WHERE amount_due > 0');
    Checks::eq('every imported bill with an amount is an invoice (WBILL-)', $bills, (int)$q("SELECT COUNT(*) FROM water_postings WHERE source_type = 'water_bill' AND status = 'posted'"));
    Checks::eq('every imported recurring charge is an invoice (RBILL-)', (int)$q('SELECT COUNT(*) FROM recurring_bills WHERE total_amount > 0'), (int)$q("SELECT COUNT(*) FROM water_postings WHERE source_type = 'recurring_bill' AND status = 'posted'"));
    Checks::eq('every imported receipt is a payment (COL-)', (int)$q('SELECT COUNT(*) FROM payment_transactions WHERE total_paid > 0'), (int)$q("SELECT COUNT(DISTINCT source_id) FROM water_postings WHERE source_type = 'collection' AND status = 'posted'"));
    Checks::eq('invoices are dated on their own billing dates', 0, (int)$q("SELECT COUNT(*) FROM water_postings w JOIN transactions t ON t.id = w.txn_id JOIN bill_readings b ON CONCAT('WBILL-', b.reading_id) = w.event_key WHERE t.txn_date <> b.billing_date"));
    Checks::eq('trial balance: debits = credits', 0.0, $money($q('SELECT COALESCE(SUM(debit - credit), 0) FROM journal_lines')));
    $mismatch = 0;
    foreach (DB::column('SELECT DISTINCT member_id FROM bill_readings LIMIT 300') as $acct) {
        if (abs($money(WaterAccounts::openBalance((int)$acct)['total']) - $money(WaterAccounts::ledgerBalance((int)$acct))) >= 0.005) {
            $mismatch++;
        }
    }
    Checks::eq('water open balance = ledger balance (300 accounts)', 0, $mismatch);
    goto report;
}
Checks::eq('imported history is not posted (the policy is opening balances)', 0, (int)$q("SELECT COUNT(*) FROM water_postings WHERE source_type = 'collection'"));

// ======================================================================================== the ledger
Checks::section('3. Opening balances in the ledger');
// open in the water records, less the bills held out because their payment evidence is still in Review
$heldRows = "SELECT r.source_key FROM data_import_rows r WHERE r.batch_id = $batchB AND r.payment_status IN ('review', 'error')";
$openWater = $money($q("SELECT COALESCE(SUM(b.amount_due - COALESCE(b.amount_paid, 0)), 0) FROM bill_readings b JOIN data_import_rows r ON r.target_bill_id = b.reading_id AND r.batch_id = $batchB
                         WHERE r.payment_status NOT IN ('review', 'error') AND COALESCE(b.bill_lifecycle_status, 'active') = 'active' AND b.amount_due - COALESCE(b.amount_paid, 0) > 0.004"));
$openRec = $money($q("SELECT COALESCE(SUM(rb.total_amount - COALESCE(rb.amount_paid, 0)), 0) FROM recurring_bills rb JOIN water_accounts wa ON wa.pkey = rb.member_id
                       WHERE rb.status NOT IN ('paid', 'cancelled', 'deleted') AND rb.total_amount - COALESCE(rb.amount_paid, 0) > 0.004
                         AND CONCAT(wa.account_number, '|', rb.bill_date) NOT IN ($heldRows)"));
[$heldN, $heldAmt] = di_opening_held(DB::pdo(), $batchB);
$posted = $money($q("SELECT COALESCE(SUM(t.total), 0) FROM water_postings w JOIN transactions t ON t.id = w.txn_id WHERE w.status = 'posted' AND t.is_voided = 0 AND t.txn_type = 'invoice'"));
printf("   open water bills %s, open charges %s, opening invoices %s; held (payment evidence in Review): %d bills, %s\n", number_format($openWater, 2), number_format($openRec, 2), number_format($posted, 2), $heldN, number_format($heldAmt, 2));
Checks::eq('opening invoices = everything still open in the water records (bills with payment evidence in Review held out)', $money($openWater + $openRec), $posted);
Checks::eq('every opening invoice is dated on the cut-over date', 0, (int)$q("SELECT COUNT(*) FROM water_postings w JOIN transactions t ON t.id = w.txn_id WHERE t.txn_type = 'invoice' AND t.txn_date <> :d", ['d' => $cutover]));
Checks::eq('trial balance: debits = credits', 0.0, $money($q('SELECT COALESCE(SUM(debit - credit), 0) FROM journal_lines')));
$clearing = (int)WaterPosting::defaultAccount('water_legacy_clearing');
Checks::eq('the offset is on the legacy water balances clearing account', $money(-$posted), $money($q('SELECT COALESCE(SUM(debit - credit), 0) FROM journal_lines WHERE account_id = :a', ['a' => $clearing])));
$mismatch = 0;
foreach (DB::column("SELECT DISTINCT b.member_id FROM bill_readings b WHERE b.amount_due - COALESCE(b.amount_paid, 0) > 0.004
                       AND NOT EXISTS (SELECT 1 FROM data_import_rows r WHERE r.target_member_id = b.member_id AND r.batch_id = $batchB AND r.payment_status IN ('review', 'error')) LIMIT 400") as $acct) {
    if (abs($money(WaterAccounts::openBalance((int)$acct)['total']) - $money(WaterAccounts::ledgerBalance((int)$acct))) >= 0.005) {
        $mismatch++;
    }
}
Checks::eq('water open balance = ledger balance (400 accounts with open bills)', 0, $mismatch);
$r = $run($batchB, 'opening', ['cutover_date' => $cutover]);
Checks::eq('running the opening balances again posts nothing', $posted, $money($q("SELECT COALESCE(SUM(t.total), 0) FROM water_postings w JOIN transactions t ON t.id = w.txn_id WHERE w.status = 'posted' AND t.is_voided = 0 AND t.txn_type = 'invoice'")));

// ======================================================================================== after the cut-over
Checks::section('4. After the cut-over: collection and the next reading');
$open = DB::row("SELECT b.*, w.account_number FROM bill_readings b JOIN water_accounts w ON w.pkey = b.member_id JOIN water_postings p ON p.event_key = CONCAT('WBILL-', b.reading_id)
                  WHERE b.amount_due - COALESCE(b.amount_paid, 0) > 0.004 AND w.status = 'A' ORDER BY b.billing_date DESC, b.reading_id LIMIT 1");
$rest = $money($open['amount_due'] - $open['amount_paid']);
$admin->postJson('water/process/processUnifiedPayment.php', ['member_id' => (int)$open['member_id'], 'items' => [['type' => 'water', 'id' => (int)$open['reading_id']]], 'tendered' => $rest,
    'transaction_date' => date('Y-m-d'), 'idempotency_key' => 'imp' . bin2hex(random_bytes(10)), 'class_id' => $importClass]);
Checks::true('an imported open bill is collected at the cashier', ($admin->json['success'] ?? false) === true, $admin->json['error'] ?? substr($admin->body, 0, 200));
Checks::eq('...its opening invoice is settled', 0.0, $money(Balances::open(Txn::load((int)WaterPosting::billTxnId('water', (int)$open['reading_id'])))));
Checks::eq('trial balance after the collection', 0.0, $money($q('SELECT COALESCE(SUM(debit - credit), 0) FROM journal_lines')));
$admin->get('index.php?p=party.statement&party=' . (int)$q('SELECT party_id FROM water_accounts WHERE pkey = :p', ['p' => $open['member_id']]) . '&from=2012-01-01&to=' . date('Y-m-d'));
Checks::true('the party statement of that account opens', $admin->status === 200 && !preg_match('/(Fatal error|Warning:|Notice:)/', $admin->body));
// the next billing period after the imported history; the reading continues from the last imported present reading
$lastEnd = (string)$q('SELECT MAX(end_date) FROM bill_periods');
$start = (new DateTimeImmutable($lastEnd))->modify('+1 minute');
if ($start->format('Y-m-d') <= date('Y-m-d')) {
    $admin->post('water/process/addBillPeriod.php', ['csrf_token' => $admin->csrf(), 'date_from' => $start->format('Y-m-d H:i'), 'date_to' => $start->modify('+1 month')->format('Y-m-d') . ' 11:59', 'duration' => 1]);
}
$pid = (int)$q("SELECT period_id FROM bill_periods WHERE status = 'open' ORDER BY start_date DESC LIMIT 1");
$acct = DB::row("SELECT w.pkey, w.account_number, (SELECT b.pres_reading FROM bill_readings b WHERE b.member_id = w.pkey ORDER BY b.billing_date DESC LIMIT 1) AS last_pres
                   FROM water_accounts w WHERE w.status = 'A' AND w.rc_id IS NOT NULL AND EXISTS (SELECT 1 FROM bill_readings b WHERE b.member_id = w.pkey) ORDER BY w.pkey LIMIT 1");
$admin->postJson('water/process/saveIndividualReading.php', ['period_id' => $pid, 'member_id' => (int)$acct['pkey'], 'pres_reading' => (int)$acct['last_pres'] + 18]);
Checks::true('a new reading is saved in a new period', ($admin->json['success'] ?? false) === true, $admin->json['message'] ?? substr($admin->body, 0, 200));
Checks::eq('...its previous reading is the last imported present reading', (int)$acct['last_pres'], (int)($admin->json['prev_reading'] ?? -1));
Checks::eq('...18 cubic metres', 18, (int)($admin->json['consumption'] ?? -1));

report:
$secsAll = round(microtime(true) - $started, 1);
$md = Checks::markdown('Data Import Management with the former program\'s files (HTTP)', [
    'Run' => date('Y-m-d H:i:s') . " ($secsAll s)", 'Server' => $base, 'Database' => DB::databaseName() . ' (new installation with the water starter set-up)', 'Ledger policy' => $policy,
    'Files' => basename($accountsFile) . ', ' . basename($billingFile), 'Environment' => 'PHP ' . PHP_VERSION . ', ' . DB::value('SELECT VERSION()'),
]);
$out = $opts['out'] ?? (__DIR__ . '/RESULTS_IMPORT_FILES.md');
file_put_contents($out, $md);
$total = count(Checks::$rows);
echo "\n" . ($total - Checks::$fail) . ' passed, ' . Checks::$fail . " failed, $total checks ({$secsAll}s). Written to $out\n";
exit(Checks::$fail ? 1 : 0);
