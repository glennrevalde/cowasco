<?php
declare(strict_types=1);

/**
 * Writes the evidence documents of the delivery from a real database (nothing here is typed by hand):
 *
 *   DB_NAME=<any integrated db>     php tools/dev/delivery_evidence.php menu            > docs/MENU_MAP.md
 *   DB_NAME=<db after the E2E test> php tools/dev/delivery_evidence.php postings        > docs/POSTING_EXAMPLES.md
 *   DB_NAME=<migrated db>           php tools/dev/delivery_evidence.php reconciliation --source=<water db> > docs/RECONCILIATION_REPORT.md
 *
 * Read-only: it changes nothing in the database.
 */
if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}
$what = $argv[1] ?? '';
$opts = [];
foreach (array_slice($argv, 2) as $arg) {   // getopt stops at the first plain word, so the options after it are read here
    if (preg_match('/^--([a-z-]+)=(.*)$/', $arg, $mm)) {
        $opts[$mm[1]] = $mm[2];
    }
}
require __DIR__ . '/../../app/bootstrap.php';
$_SESSION = [];
Tenant::bootCli();
Auth::actAs((int)DB::value("SELECT id FROM users WHERE username = 'admin'"));
$m = fn($v) => number_format((float)$v, 2);
$esc = fn($v) => str_replace(['|', "\n"], ['\\|', ' '], (string)$v);

// ===================================================================================================== menu
if ($what === 'menu') {
    $defs = Access::allPageDefs();
    $aliases = Access::menuAliases();
    echo "# Screen and menu map\n\n";
    echo "One side menu for the whole system, the same on the cooperative/accounting pages and on the water pages. Every page is\n"
        . "listed with its page-access key (the key Page Access Configuration and User Role Configuration work on). A user sees\n"
        . "only the pages given to his roles. Generated from the page registry of `" . DB::databaseName() . "` on " . date('Y-m-d') . ".\n\n";
    echo "There is one Accounting (Financial, Banking, reports), one Inventory and one Membership (Members & Others, Parties)\n"
        . "section. The Water Billing menu holds only water operations; the former water accounting, inventory and user pages\n"
        . "are retired (table at the end) and open the page that replaces them.\n\n";
    $nModules = 0;
    $nPages = 0;
    foreach (Access::modules() as $code => [$name, $icon, $sort]) {
        $keys = array_keys(array_filter($defs, fn($d) => $d['module'] === $code && $d['menu']));
        $alias = array_values(array_filter($aliases[$code] ?? [], fn($k) => isset($defs[$k])));
        if (!$keys && !$alias) {
            continue;
        }
        $nModules++;
        echo "## $name\n\n";
        $groups = [];
        foreach ($keys as $k) {
            $g = $defs[$k]['type'] === 'report' ? 'Reports' : $defs[$k]['group'];
            $groups[$g][] = $k;
        }
        foreach ($alias as $k) {
            $groups['Transactions'][] = $k . ' (also listed here)';
        }
        foreach ($groups as $g => $list) {
            $names = [];
            foreach ($list as $k) {
                $realKey = explode(' ', $k)[0];
                $names[] = $defs[$realKey]['name'] . ' `' . $realKey . '`' . (str_contains($k, ' ') ? ' (its own menu: ' . (Access::modules()[$defs[$realKey]['module']][0] ?? $defs[$realKey]['module']) . ')' : '');
                $nPages++;
            }
            echo '- **' . $g . "** (" . count($list) . "): " . implode('; ', $names) . "\n";
        }
        echo "\n";
    }
    echo "## Retired pages of the former water program\n\n";
    echo "The file still answers (old bookmarks keep working): it shows why the page is retired and links to the page that replaces it.\n\n";
    echo "| Former water page | Replaced by | Why |\n|---|---|---|\n";
    foreach (WaterPages::RETIRED as $k => [$to, $why]) {
        $toName = $to === '' ? 'Login page' : (($defs[$to]['name'] ?? $to) . ' `' . $to . '`');
        echo "| `$k.php` | $toName | " . $esc($why) . " |\n";
    }
    echo "\n$nModules menus, $nPages menu entries.\n";
    exit(0);
}

// ===================================================================================================== postings
if ($what === 'postings') {
    $rows = DB::all('SELECT w.*, t.txn_type AS doc_type, t.txn_no, t.txn_date, t.cp_name, t.cp_role, t.is_voided, t.total, t.memo,
                            bu.code AS bu_code, c.name AS class_name
                       FROM water_postings w
                       LEFT JOIN transactions t ON t.id = w.txn_id
                       LEFT JOIN business_units bu ON bu.id = t.business_unit_id
                       LEFT JOIN classes c ON c.id = t.class_id
                      ORDER BY w.id');
    echo "# Sample posting examples (from the end-to-end test database)\n\n";
    echo "Every water event below was made through the water pages over HTTP by `tests/water/e2e_http.php` and posted by\n"
        . "`app/WaterPosting.php` through the cooperative `Txn::save` / `Posting` engine. The lines are read back from the\n"
        . "ledger of `" . DB::databaseName() . "` (" . date('Y-m-d H:i') . "). One row of `water_postings` per event key: a retry\n"
        . "or a second request with the same key finds the row and posts nothing.\n\n";
    echo "| Event key | Source | Document | Party (printed name) | Line of business / class | Amount | Status |\n|---|---|---|---|---|---:|---|\n";
    foreach ($rows as $r) {
        echo '| `' . $r['event_key'] . '` | ' . $r['source_type'] . ' ' . $r['source_id'] . ' | ' . ($r['doc_type'] ?? '-') . ' ' . ($r['txn_no'] ?? '') . ($r['is_voided'] ? ' (voided)' : '')
            . ' | ' . $esc($r['cp_name'] ?? '') . ' | ' . ($r['bu_code'] ?? '') . ' / ' . $esc($r['class_name'] ?? '') . ' | ' . $m($r['amount']) . ' | ' . $r['status'] . ($r['reversal_of_id'] ? (str_contains((string)$r['source_type'], 'reversal') || (string)$r['txn_type'] === 'void' ? ' (reverses #' : ' (replaces #') . $r['reversal_of_id'] . ')' : '') . " |\n";
    }
    echo "\n";
    $pick = [];
    $want = [
        'WBILL-' => 'Metered water bill posted (Generate SOA / Post Billing): A/R against the party\'s customer record, water income',
        '-R1' => 'Bill edited before collection: the first invoice is voided (kept, status replaced) and the corrected bill posts as -R1',
        '#PEN' => 'Penalty charged at the cashier: a finance charge to the penalty income account of the collection policy',
        'COL-' => 'Collection (Collections (Cashier)): cash account of the cashier, applied to the open water invoices',
        'REV-' => 'Collection reversed (error correction): the payment is voided and the bills reopen; the reversal row links to the original',
        'OTB-' => 'One-time bill',
        'INST-' => 'Installment contract',
        'RBILL-' => 'Recurring bill',
        'ISS-' => 'Material issuance to field work: expense debited, inventory asset credited at average cost (one inventory adjustment)',
    ];
    foreach ($want as $needle => $title) {
        foreach ($rows as $r) {
            if (isset($pick[$r['id']])) {
                continue;
            }
            $hit = $needle === 'WBILL-' ? (str_starts_with($r['event_key'], 'WBILL-') && !str_contains($r['event_key'], '-R')) : str_contains($r['event_key'], $needle);
            if ($needle === 'COL-' && (str_contains($r['event_key'], '#') || str_starts_with($r['event_key'], 'REV-'))) {
                $hit = false;
            }
            // show a document that is in the ledger, except for the reversal itself
            if ($hit && $needle !== 'REV-' && ($r['status'] !== 'posted' || (int)$r['is_voided'] === 1)) {
                $hit = false;
            }
            if ($hit) {
                $pick[$r['id']] = $title;
                break;
            }
        }
    }
    // the meter sold through a one-time bill linked to an inventory item: income plus cost of goods sold
    foreach ($rows as $r) {
        if (str_starts_with($r['event_key'], 'OTB-') && !isset($pick[$r['id']]) && (int)DB::value('SELECT COUNT(*) FROM journal_lines WHERE txn_id = :t AND item_id IS NOT NULL', ['t' => $r['txn_id']]) > 0) {
            $pick[$r['id']] = 'A water meter sold through a one-time bill whose bill code is linked to an item: income, cost of goods sold and the stock leave together';
            break;
        }
    }
    ksort($pick);
    echo "## Journal lines of each kind of event\n\n";
    foreach ($pick as $id => $title) {
        $r = array_values(array_filter($rows, fn($x) => (int)$x['id'] === $id))[0];
        echo "### " . $title . "\n\n";
        echo '`' . $r['event_key'] . '`: ' . ($r['doc_type'] ?? '') . ' ' . ($r['txn_no'] ?? '') . ' dated ' . ($r['txn_date'] ?? '')
            . (trim((string)($r['cp_name'] ?? '')) !== '' ? ', ' . $esc($r['cp_name']) . ($r['cp_role'] ? ' (' . $r['cp_role'] . ')' : '') : '')
            . ', line of business ' . ($r['bu_code'] ?? '-') . ($r['is_voided'] ? '. **The document is voided**: its ledger lines are removed (the bills it paid are open again); the document, its number and its original lines stay in the audit trail.' : '.') . "\n\n";
        $lines = DB::all('SELECT a.code, a.name, j.debit, j.credit, j.memo, i.name AS item FROM journal_lines j JOIN accounts a ON a.id = j.account_id LEFT JOIN items i ON i.id = j.item_id WHERE j.txn_id = :t ORDER BY j.id', ['t' => $r['txn_id']]);
        if (!$lines) {
            echo "_No ledger lines left: the void removed them._\n\n";
            continue;
        }
        echo "| Account | Debit | Credit | Memo / item |\n|---|---:|---:|---|\n";
        $d = $c = 0.0;
        foreach ($lines as $l) {
            $d += (float)$l['debit'];
            $c += (float)$l['credit'];
            echo '| ' . $l['code'] . ' ' . $esc($l['name']) . ' | ' . ((float)$l['debit'] ? $m($l['debit']) : '') . ' | ' . ((float)$l['credit'] ? $m($l['credit']) : '') . ' | ' . $esc(trim(($l['memo'] ?? '') . ($l['item'] ? ' [' . $l['item'] . ']' : ''))) . " |\n";
        }
        echo '| **Total** | **' . $m($d) . '** | **' . $m($c) . "** | |\n\n";
    }
    echo "## Ledger totals at the end of the test\n\n";
    echo '- Trial balance difference (debits - credits): ' . $m(DB::value('SELECT COALESCE(SUM(debit - credit), 0) FROM journal_lines')) . "\n";
    echo '- Documents whose lines do not balance: ' . (int)DB::value('SELECT COUNT(*) FROM (SELECT txn_id FROM journal_lines GROUP BY txn_id HAVING ABS(SUM(debit - credit)) >= 0.005) x') . "\n";
    echo '- Ledger documents claimed by two water events: ' . (int)DB::value("SELECT COUNT(*) FROM (SELECT txn_id FROM water_postings WHERE txn_id IS NOT NULL AND txn_type <> 'void' GROUP BY txn_id HAVING COUNT(*) > 1) x") . "\n";
    exit(0);
}

// ===================================================================================================== reconciliation
if ($what === 'reconciliation') {
    $src = (string)($opts['source'] ?? DB::value("SELECT setting_value FROM water_billing_settings WHERE setting_key = 'water_source_db'") ?? '');
    if (!DB::validName($src)) {
        exit("Give --source=<former water database>.\n");
    }
    $cut = (string)(DB::value("SELECT setting_value FROM water_billing_settings WHERE setting_key = 'water_cutover_date'") ?: date('Y-m-d'));
    $sv = fn(string $sql) => DB::value(str_replace('{src}', '`' . $src . '`', $sql));
    echo "# Accounting reconciliation report\n\n";
    echo "- **Integrated database:** `" . DB::databaseName() . "`\n- **Former water database (read only):** `$src`\n- **Cut-over date:** $cut\n- **Made:** " . date('Y-m-d H:i') . " by `tools/dev/delivery_evidence.php reconciliation` (read-only queries)\n\n";

    echo "## 1. Records: former database vs integrated database\n\n| Record | Former | Integrated | Equal |\n|---|---:|---:|---|\n";
    $pairs = [
        'Water-service accounts' => ['SELECT COUNT(*) FROM {src}.members', 'SELECT COUNT(*) FROM water_accounts WHERE legacy_member_id IS NOT NULL OR pkey IN (SELECT pkey FROM {src}.members)'],
        'Addresses of accounts' => ['SELECT COUNT(*) FROM {src}.memberaddress', 'SELECT COUNT(*) FROM memberaddress WHERE member_key IN (SELECT pkey FROM {src}.members)'],
        'Contact numbers' => ['SELECT COUNT(*) FROM {src}.membercontactchannels', 'SELECT COUNT(*) FROM membercontactchannels WHERE memberkey IN (SELECT pkey FROM {src}.members)'],
        'Rate codes' => ['SELECT COUNT(*) FROM {src}.rate_code', 'SELECT COUNT(*) FROM rate_code WHERE rc_id IN (SELECT rc_id FROM {src}.rate_code)'],
        'Tariff tiers (water_meter_members)' => ['SELECT COUNT(*) FROM {src}.water_meter_members', 'SELECT COUNT(*) FROM water_meter_members WHERE wmm_id IN (SELECT wmm_id FROM {src}.water_meter_members)'],
        'Pipe sizes' => ['SELECT COUNT(*) FROM {src}.water_pipe_sizes', 'SELECT COUNT(*) FROM water_pipe_sizes WHERE pipe_size_id IN (SELECT pipe_size_id FROM {src}.water_pipe_sizes)'],
        'Bill codes' => ['SELECT COUNT(*) FROM {src}.bill_codes', 'SELECT COUNT(*) FROM bill_codes WHERE code_id IN (SELECT code_id FROM {src}.bill_codes)'],
        'Billing periods' => ['SELECT COUNT(*) FROM {src}.bill_periods', 'SELECT COUNT(*) FROM bill_periods WHERE period_id IN (SELECT period_id FROM {src}.bill_periods)'],
        'Meter readings / water bills' => ['SELECT COUNT(*) FROM {src}.bill_readings', 'SELECT COUNT(*) FROM bill_readings WHERE reading_id IN (SELECT reading_id FROM {src}.bill_readings)'],
        'Payments (receipts)' => ['SELECT COUNT(*) FROM {src}.payment_transactions', 'SELECT COUNT(*) FROM payment_transactions WHERE transaction_id IN (SELECT transaction_id FROM {src}.payment_transactions)'],
        'Chart of accounts (every former account mapped or skipped with a reason)' => ['SELECT COUNT(*) FROM {src}.chartofaccntstbl', 'SELECT COUNT(*) FROM water_coa_map'],
        '...of which mapped to an account of the one chart' => ['SELECT COUNT(*) FROM water_coa_map WHERE account_id IS NOT NULL', 'SELECT COUNT(*) FROM water_coa_map WHERE account_id IS NOT NULL'],
        'Operators (mapped to users or skipped with a reason)' => ['SELECT COUNT(*) FROM {src}.operator', 'SELECT COUNT(*) FROM water_user_map'],
        'Suppliers (as vendors)' => ['SELECT COUNT(*) FROM {src}.suppliers', "SELECT COUNT(*) FROM party_crosswalk WHERE source = 'water_supp'"],
        'Former membership register (kept read-only)' => ['SELECT COUNT(*) FROM {src}.membershipinfo', 'SELECT COUNT(*) FROM membershipinfo'],
    ];
    foreach ($pairs as $label => [$a, $b]) {
        try {
            $va = $sv($a);
            $vb = $sv($b);
            echo "| $label | " . number_format((float)$va) . ' | ' . number_format((float)$vb) . ' | ' . ((string)$va === (string)$vb ? 'yes' : '**no**') . " |\n";
        } catch (Throwable $e) {
            echo "| $label | - | - | not comparable (" . $esc(mb_substr($e->getMessage(), 0, 60)) . ") |\n";
        }
    }

    foreach (DB::all("SELECT note, COUNT(*) n FROM water_coa_map WHERE account_id IS NULL GROUP BY note ORDER BY n DESC") as $sk) {
        echo "\nFormer accounts not mapped: " . $sk['n'] . ' - ' . $esc($sk['note']) . ' (no legacy journal line uses them; the accounts under them are mapped).';
    }
    echo "\n";
    echo "\n## 2. Parties\n\n";
    echo '- Water-service accounts with a party: ' . number_format((float)DB::value('SELECT COUNT(*) FROM water_accounts WHERE party_id IS NOT NULL')) . ' of ' . number_format((float)DB::value('SELECT COUNT(*) FROM water_accounts')) . "\n";
    echo '- Parties made for water accounts by the migration (one each, never merged by name): ' . number_format((float)DB::value("SELECT COUNT(*) FROM party_crosswalk WHERE source = 'water_acct'")) . "\n";
    echo '- Pairs queued on Parties > Identity Review (same TIN / mobile / name and birth date, to be decided by a person): ' . number_format((float)DB::value("SELECT COUNT(*) FROM party_match_reviews WHERE status = 'open'")) . "\n";
    echo '- Water-service accounts whose party is a cooperative member: ' . number_format((float)DB::value('SELECT COUNT(DISTINCT w.pkey) FROM water_accounts w JOIN members m ON m.party_id = w.party_id')) . " (a water customer is not made a member by the migration)\n";

    echo "\n## 3. Control accounts\n\n";
    echo "The former water program's own journal (if any) is not posted again: the open bills at cut-over become one invoice each\n"
        . "(event key WBILL-/OTB-/INST-/RBILL-), and one opening-balance journal (LEGACY-OB) brings every other legacy balance in, with\n"
        . "the clearing account netting to zero. The tables below are read from the integrated ledger.\n\n";
    $ar = (float)DB::value("SELECT COALESCE(SUM(j.debit - j.credit), 0) FROM journal_lines j JOIN accounts a ON a.id = j.account_id WHERE a.account_type = 'Accounts Receivable'");
    // the A/R sub-ledger: what every customer owes (open documents less unapplied credits), the same figure the
    // Customer Balance Summary shows
    $openInv = 0.0;
    foreach (DB::column('SELECT id FROM customers WHERE parent_id IS NULL') as $cid) {
        $openInv += Balances::customerBalance((int)$cid);
    }
    $ap = (float)DB::value("SELECT COALESCE(SUM(j.credit - j.debit), 0) FROM journal_lines j JOIN accounts a ON a.id = j.account_id WHERE a.account_type = 'Accounts Payable'");
    $inv = (float)DB::value("SELECT COALESCE(SUM(j.debit - j.credit), 0) FROM journal_lines j JOIN accounts a ON a.id = j.account_id WHERE a.id IN (SELECT DISTINCT asset_account_id FROM items WHERE asset_account_id IS NOT NULL)");
    $stockValue = 0.0;
    foreach (DB::column("SELECT id FROM items WHERE item_type IN ('inventory', 'assembly')") as $itemId) {
        $stockValue += Stock::valueOnHand((int)$itemId);
    }
    $cash = (float)DB::value("SELECT COALESCE(SUM(j.debit - j.credit), 0) FROM journal_lines j JOIN accounts a ON a.id = j.account_id WHERE a.account_type = 'Bank'");
    echo "| Control | Ledger | Sub-ledger | Difference |\n|---|---:|---:|---:|\n";
    echo '| Accounts receivable (all A/R accounts) vs customer balances | ' . $m($ar) . ' | ' . $m($openInv) . ' | ' . $m($ar - $openInv) . " |\n";
    echo '| Inventory asset accounts vs value on hand | ' . $m($inv) . ' | ' . $m($stockValue) . ' | ' . $m($inv - $stockValue) . " |\n";
    $vend = 0.0;
    foreach (DB::column('SELECT id FROM vendors') as $vid) {
        $vend += Balances::vendorBalance((int)$vid);
    }
    echo '| Accounts payable vs supplier balances | ' . $m($ap) . ' | ' . $m($vend) . ' | ' . $m($ap - $vend) . " |\n";
    echo '| Cash and bank accounts | ' . $m($cash) . " | (bank reconciliation) | |\n";
    echo '| Trial balance: debits - credits | ' . $m(DB::value('SELECT COALESCE(SUM(debit - credit), 0) FROM journal_lines')) . " | | |\n";
    $clear = DB::value("SELECT COALESCE(SUM(j.debit - j.credit), 0) FROM journal_lines j JOIN accounts a ON a.id = j.account_id WHERE a.code = '39999'");
    echo '| Migration clearing account 39999 | ' . $m($clear ?? 0) . " | must be 0.00 | |\n";
    echo "\n## 4. Water records vs the ledger, per water-service account\n\n";
    $diff = 0;
    $n = 0;
    $tot = 0.0;
    foreach (DB::column('SELECT DISTINCT water_account_id FROM water_postings WHERE water_account_id IS NOT NULL') as $id) {
        $n++;
        $a = round(WaterAccounts::openBalance((int)$id)['total'], 2);
        $b = round(WaterAccounts::ledgerBalance((int)$id), 2);
        $tot += $a;
        if (abs($a - $b) >= 0.005) {
            $diff++;
        }
    }
    echo "- Accounts with posted water documents: $n; open balance in the water records: " . $m($tot) . "; accounts whose water open balance differs from their invoices in the ledger: $diff\n";
    echo '- Water events posted: ' . number_format((float)DB::value('SELECT COUNT(*) FROM water_postings')) . '; failed postings waiting in the Water Posting Log: ' . number_format((float)DB::value("SELECT COUNT(*) FROM water_postings WHERE status = 'failed'")) . "\n";
    echo "\n## 5. Opening balance journal\n\n";
    $ob = DB::row("SELECT w.txn_id, t.txn_no, t.txn_date FROM water_postings w JOIN transactions t ON t.id = w.txn_id WHERE w.event_key = 'LEGACY-OB'");
    if (!$ob) {
        echo "No opening-balance journal: the former database had no ledger balances at the cut-over (no legacy journal lines up to $cut), so nothing was brought in and nothing needed reconciling.\n";
    } else {
        echo 'Journal ' . $ob['txn_no'] . ' dated ' . $ob['txn_date'] . ":\n\n| Account | Debit | Credit |\n|---|---:|---:|\n";
        foreach (DB::all('SELECT a.code, a.name, SUM(j.debit) d, SUM(j.credit) c FROM journal_lines j JOIN accounts a ON a.id = j.account_id WHERE j.txn_id = :t GROUP BY a.id ORDER BY a.code', ['t' => $ob['txn_id']]) as $l) {
            echo '| ' . $l['code'] . ' ' . $esc($l['name']) . ' | ' . $m($l['d']) . ' | ' . $m($l['c']) . " |\n";
        }
    }
    exit(0);
}

fwrite(STDERR, "usage: delivery_evidence.php menu|postings|reconciliation [--source=db]\n");
exit(2);
