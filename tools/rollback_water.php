<?php
declare(strict_types=1);

/**
 * Undoes a run of tools/migrate_water.php. For a FAILED CUTOVER REHEARSAL BEFORE GO-LIVE ONLY.
 *
 *   php tools/rollback_water.php [--run=RUN_ID] [--source=DB] [--company=N] [--dry-run]
 *
 *   --run      the run to undo (water_migration_log.run_id); default: the last run that wrote something
 *   --source   the former water database the run copied from (default: the water_source_db setting the run wrote)
 *   --dry-run  shows what would be deleted and rolls back
 *
 * What it removes, in one transaction:
 *   - the ledger documents the run posted (the open-balance invoices and the opening-balance journal: water_postings
 *     of the run and their transactions, lines and applications)
 *   - the rows the run copied into the water tables (rows whose primary key exists in the source database, in the
 *     tables the run reported as written), the legacy_* tables and water_audit_logs included
 *   - the customers made for water-service accounts by the run's postings, the parties of the water-service
 *     accounts and suppliers (when nothing else uses them), the vendors the run created, their crosswalk and role-log rows
 *   - the users, roles, user roles and page overrides the run created (tracked in its log notes and water_user_map)
 *   - the accounts imported from the legacy chart (how = imported) that have no journal lines, the clearing account
 *     the run created, the water default accounts the run filled, the classes / cooperative branches it made
 *   - water_coa_map, water_user_map, the Identity Review pairs of the removed parties
 *
 * It REFUSES to run when water_postings rows exist that the run did not create (the system has been used since:
 * there is nothing to rehearse any more, and a live ledger is never rolled back), or when the source database is
 * not reachable (it is the list of what was copied). The audit trail is append-only and keeps the record of both
 * the migration and the rollback.
 */
if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/lib.php';
require __DIR__ . '/water_migration_lib.php';

$opts = getopt('', ['run:', 'source:', 'company:', 'dry-run', 'help']);
if (isset($opts['help'])) {
    wm_out('Usage: php tools/rollback_water.php [--run=RUN_ID] [--source=DB] [--company=N] [--dry-run]');
    exit(0);
}
$_SESSION = [];
Tenant::bootCli(isset($opts['company']) ? (int)$opts['company'] : null);
$adminId = (int)(DB::value("SELECT id FROM users WHERE is_platform = 0 AND status = 'active' AND created_via <> 'migration' AND id IN (SELECT ur.user_id FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE r.is_admin = 1) ORDER BY id LIMIT 1")
    ?: DB::value("SELECT id FROM users WHERE username = 'admin'") ?: 0);
if (!$adminId) {
    wm_err('No administrator user found in the target database.');
    exit(1);
}
Auth::actAs($adminId);
$dryRun = isset($opts['dry-run']);
$dstDb = DB::databaseName();

// ---------------------------------------------------------------------------------------- the run
$runId = (string)($opts['run'] ?? '');
if ($runId === '') {
    $runId = (string)(DB::value("SELECT run_id FROM water_migration_log WHERE status = 'done' AND rows_written > 0 AND phase <> 'verify' ORDER BY id DESC LIMIT 1") ?: '');
    if ($runId === '') {
        wm_err('No migration run that wrote anything was found in water_migration_log.');
        exit(1);
    }
}
$logRows = DB::all("SELECT * FROM water_migration_log WHERE run_id = :r AND status = 'done' ORDER BY id", ['r' => $runId]);
if (!$logRows) {
    wm_err("Run $runId has no completed phase in water_migration_log (a dry run cannot be rolled back; nothing was kept).");
    exit(1);
}
$notes = [];
$writtenTables = [];
foreach ($logRows as $r) {
    $n = json_decode((string)$r['notes'], true) ?: [];
    $notes[$r['phase']] = $n;
    foreach ($n['tables'] ?? [] as $t => $c) {
        if ((int)($c['written'] ?? 0) > 0) {
            $writtenTables[$t] = true;
        }
    }
}
$srcDb = (string)($opts['source'] ?? Settings::get('water_source_db', ''));
if ($srcDb === '') {
    wm_err('The source database is not known (the run did not reach the history phase). Pass --source=<former water database>.');
    exit(1);
}
try {
    $src = wm_open_source($srcDb);
} catch (Throwable $e) {
    wm_err('The source database is needed to know which rows were copied: ' . $e->getMessage());
    exit(1);
}
wm_out('Rolling back water migration run ' . $runId . ' on ' . $dstDb . ' (source ' . $srcDb . ')' . ($dryRun ? '  [DRY RUN]' : ''));
wm_out('  phases found: ' . implode(', ', array_keys($notes)));

// ---------------------------------------------------------------------------------------- refusals
$h = $notes['history'] ?? null;
$pFrom = (int)($h['postings_from'] ?? 1);
$pTo = (int)($h['postings_to'] ?? 0);
// the postings of the run: those its history phase wrote, and the savings opening import of its membership phase
$mem = $notes['membership'] ?? [];
$savPosting = !empty($mem['savings_import_id'])
    ? (int)(DB::value("SELECT id FROM water_postings WHERE event_key = 'LEGACY-SAVOB' AND source_id = :s", ['s' => (string)$mem['savings_import_id']]) ?: 0) : 0;
$foreignPostings = (int)DB::value('SELECT COUNT(*) FROM water_postings WHERE (id < :a OR id > :b) AND id <> :s', ['a' => $pFrom, 'b' => $pTo, 's' => $savPosting]);
if ($foreignPostings > 0) {
    wm_err("REFUSED: $foreignPostings water_postings row(s) exist that run $runId did not create (the water module has been used since, or a later run posted: roll back the later run first). A rollback is only for a rehearsal that has not gone live.");
    exit(1);
}
$runTxnIds = array_map('intval', DB::column('SELECT DISTINCT txn_id FROM water_postings WHERE txn_id IS NOT NULL AND id BETWEEN :a AND :b', ['a' => $pFrom, 'b' => $pTo]));
// the legacy members and savings the membership phase imported: refused when anything happened on them since
$memberIds = array_values(array_filter(array_map('intval', $mem['members_created'] ?? [])));
$savTxnId = (int)($mem['savings_txn_id'] ?? 0);
if ($memberIds) {
    [$in, $p] = DB::in('m', $memberIds);
    $activity = (int)DB::value("SELECT COUNT(*) FROM sav_txns t JOIN sav_accounts a ON a.id = t.account_id WHERE a.member_id IN $in AND (t.txn_id IS NULL OR t.txn_id <> :x)", $p + ['x' => $savTxnId])
        + (int)DB::value("SELECT COUNT(*) FROM loan_accounts WHERE member_id IN $in", $p)
        + (int)DB::value("SELECT COUNT(*) FROM journal_lines j JOIN other_names o ON o.id = j.other_name_id WHERE o.member_id IN $in AND j.txn_id <> :x", $p + ['x' => $savTxnId]);
    if ($activity > 0) {
        wm_err("REFUSED: the members imported by run $runId have savings, loan or ledger activity recorded after the import ($activity row(s)). Nothing was changed.");
        exit(1);
    }
}
// any other document applied to / applying the run's invoices means live activity
if ($runTxnIds) {
    [$in, $p] = DB::in('t', $runTxnIds);
    $others = (int)DB::value("SELECT COUNT(*) FROM payment_applications WHERE (target_txn_id IN $in AND payment_txn_id NOT IN $in) OR (payment_txn_id IN $in AND target_txn_id NOT IN $in)", $p);
    if ($others > 0) {
        wm_err('REFUSED: documents recorded after the migration are applied to the migrated invoices. Nothing was changed.');
        exit(1);
    }
}

// ---------------------------------------------------------------------------------------- the work
$report = [];
$count = function (string $what, int $n) use (&$report) {
    $report[] = [$what, $n];
    wm_out(sprintf('   %-70s %8d', $what, $n));
};

DB::begin();
try {
    // 1. ledger documents of the run
    if ($runTxnIds) {
        [$in, $p] = DB::in('t', $runTxnIds);
        $n = DB::run("DELETE FROM payment_applications WHERE payment_txn_id IN $in OR target_txn_id IN $in", $p)->rowCount();
        $count('payment_applications of the run', $n);
        $n = DB::run("DELETE FROM journal_lines WHERE txn_id IN $in", $p)->rowCount();
        $count('journal_lines of the run', $n);
        try {
            $n = DB::run("DELETE FROM inventory_movements WHERE txn_id IN $in", $p)->rowCount();
            $count('inventory_movements of the run', $n);
        } catch (Throwable $e) {
            // no such table on an older schema
        }
        $n = DB::run("DELETE FROM transaction_lines WHERE txn_id IN $in", $p)->rowCount();
        $count('transaction_lines of the run', $n);
        $n = DB::run("DELETE FROM transactions WHERE id IN $in", $p)->rowCount();
        $count('transactions of the run (invoices, opening balance)', $n);
    }
    $n = DB::run('DELETE FROM water_postings WHERE id BETWEEN :a AND :b', ['a' => $pFrom, 'b' => $pTo])->rowCount();
    $count('water_postings of the run', $n);

    // 1b. the legacy cooperative register the membership phase imported: the savings opening voucher, the savings
    //     accounts it opened, the members, their ledger names and parties (only rows the run made, checked above)
    if ($savTxnId) {
        $n = DB::run('DELETE FROM sav_txns WHERE txn_id = :x', ['x' => $savTxnId])->rowCount();
        $count('savings ledger lines of the opening import', $n);
        DB::run('DELETE FROM journal_lines WHERE txn_id = :x', ['x' => $savTxnId]);
        DB::run('DELETE FROM transaction_lines WHERE txn_id = :x', ['x' => $savTxnId]);
        $n = DB::run('DELETE FROM transactions WHERE id = :x', ['x' => $savTxnId])->rowCount();
        $count('savings opening voucher', $n);
        DB::run('DELETE FROM coop_imports WHERE id = :i', ['i' => (int)$mem['savings_import_id']]);
        $n = DB::run("DELETE FROM water_postings WHERE event_key = 'LEGACY-SAVOB'")->rowCount();
        $count('water_postings of the savings import', $n);
    }
    if ($memberIds) {
        [$in, $p] = DB::in('m', $memberIds);
        $n = DB::run("DELETE FROM sav_accounts WHERE member_id IN $in AND NOT EXISTS (SELECT 1 FROM sav_txns t WHERE t.account_id = sav_accounts.id)", $p)->rowCount();
        $count('savings accounts opened for the imported members', $n);
        $partyIds = array_map('intval', DB::column("SELECT party_id FROM members WHERE id IN $in AND party_id IS NOT NULL", $p));
        foreach (['member_status_history'] as $t) {
            try {
                DB::run("DELETE FROM $t WHERE member_id IN $in", $p);
            } catch (Throwable $e) {
                // table not present on this schema
            }
        }
        $onIds = array_map('intval', DB::column("SELECT id FROM other_names WHERE member_id IN $in", $p));
        DB::run("UPDATE members SET other_name_id = NULL WHERE id IN $in", $p);
        if ($onIds) {
            [$oin, $op] = DB::in('o', $onIds);
            DB::run("DELETE FROM party_crosswalk WHERE source = 'other' AND source_id IN $oin", $op);
            DB::run("DELETE FROM other_names WHERE id IN $oin", $op);
        }
        DB::run("DELETE FROM party_crosswalk WHERE source = 'member' AND source_id IN $in", $p);
        $n = DB::run("DELETE FROM members WHERE id IN $in", $p)->rowCount();
        $count('members imported from the legacy register', $n);
        if (!empty($mem['members_import_id'])) {
            DB::run('DELETE FROM coop_imports WHERE id = :i', ['i' => (int)$mem['members_import_id']]);
        }
        $pDel = 0;
        foreach ($partyIds as $pid) {
            $roles = (int)DB::value('SELECT COUNT(*) FROM customers WHERE party_id = :p', ['p' => $pid]) + (int)DB::value('SELECT COUNT(*) FROM vendors WHERE party_id = :p', ['p' => $pid])
                + (int)DB::value('SELECT COUNT(*) FROM members WHERE party_id = :p', ['p' => $pid]) + (int)DB::value('SELECT COUNT(*) FROM other_names WHERE party_id = :p', ['p' => $pid])
                + (int)DB::value('SELECT COUNT(*) FROM transactions WHERE party_id = :p', ['p' => $pid]) + (int)DB::value('SELECT COUNT(*) FROM water_accounts WHERE party_id = :p', ['p' => $pid]);
            if ($roles > 0) {
                continue;
            }
            DB::run('DELETE FROM party_role_log WHERE party_id = :p', ['p' => $pid]);
            DB::run('DELETE FROM party_match_reviews WHERE party_a = :p OR party_b = :p2', ['p' => $pid, 'p2' => $pid]);
            DB::run('DELETE FROM parties WHERE id = :p', ['p' => $pid]);
            $pDel++;
        }
        $count('parties of the imported members', $pDel);
    }
    if (!empty($mem['savings_import_id']) || $memberIds) {
        $n = DB::run("DELETE FROM party_crosswalk WHERE source = 'water_coopmb'")->rowCount();
        $count('party_crosswalk rows (water_coopmb)', $n);
    }
    if (!empty($mem['savings_clearing_created'])) {
        $id = (int)$mem['savings_clearing_created'];
        if (!(int)DB::value('SELECT COUNT(*) FROM journal_lines WHERE account_id = :a', ['a' => $id])) {
            $count('legacy savings clearing account', DB::run('DELETE FROM accounts WHERE id = :a', ['a' => $id])->rowCount());
        }
    }

    // 1c. items the bill codes phase made for inventory bill codes (only when nothing moved them)
    $n = 0;
    foreach ($notes['bill_codes']['items_created'] ?? [] as $itemId) {
        $used = (int)DB::value('SELECT COUNT(*) FROM transaction_lines WHERE item_id = :i', ['i' => $itemId]) + (int)DB::value('SELECT COUNT(*) FROM inventory_movements WHERE item_id = :i', ['i' => $itemId]);
        if ($used > 0) {
            continue;
        }
        DB::run('UPDATE bill_codes SET item_id = NULL WHERE item_id = :i', ['i' => $itemId]);
        DB::run('DELETE FROM item_site_stock WHERE item_id = :i', ['i' => $itemId]);
        $n += DB::run('DELETE FROM items WHERE id = :i', ['i' => $itemId])->rowCount();
    }
    $count('items made for inventory bill codes', $n);

    // 2. the copied rows, children first (reverse copy order), only in the tables the run wrote
    $pairs = array_reverse(wm_all_tables(), true);
    $waterAccountKeys = [];
    foreach ($pairs as $srcTable => $dstTable) {
        if (!isset($writtenTables[$dstTable]) || !wm_table_exists(DB::pdo(), $srcDb, $srcTable)) {
            continue;
        }
        $pk = wm_pk(DB::pdo(), $dstDb, $dstTable);
        $on = implode(' AND ', array_map(fn($c) => "t.`$c` = s.`$c`", $pk));
        if ($dstTable === 'water_accounts') {
            $waterAccountKeys = array_map('intval', DB::column("SELECT t.pkey FROM water_accounts t JOIN `$srcDb`.members s ON t.pkey = s.pkey WHERE t.legacy_member_id IS NOT NULL"));
        }
        $n = DB::run("DELETE t FROM `$dstTable` t JOIN `$srcDb`.`$srcTable` s ON $on" . ($dstTable === 'water_accounts' ? ' WHERE t.legacy_member_id IS NOT NULL' : ''))->rowCount();
        $count("rows copied into $dstTable", $n);
    }

    // 3. customers and parties of the water-service accounts
    if ($waterAccountKeys) {
        [$in, $p] = DB::in('w', $waterAccountKeys);
        $partyIds = array_map('intval', DB::column("SELECT id FROM parties WHERE src_table = 'water_acct' AND src_id IN $in", $p));
        $xw = array_map('intval', DB::column("SELECT party_id FROM party_crosswalk WHERE source = 'water_acct' AND source_id IN $in", $p));
        $partyIds = array_values(array_unique(array_merge($partyIds, $xw)));
        $n = DB::run("DELETE FROM party_crosswalk WHERE source = 'water_acct' AND source_id IN $in", $p)->rowCount();
        $count('party_crosswalk rows (water_acct)', $n);
        $custDeleted = 0;
        $partyDeleted = 0;
        $partyKept = 0;
        foreach (array_chunk($partyIds, 500) as $chunk) {
            [$pin, $pp] = DB::in('p', $chunk);
            // customers made for the run's postings: gone when no document is left on them
            foreach (DB::all("SELECT id, party_id FROM customers WHERE party_id IN $pin", $pp) as $c) {
                $used = (int)DB::value('SELECT COUNT(*) FROM transactions WHERE customer_id = :c', ['c' => $c['id']])
                    + (int)DB::value('SELECT COUNT(*) FROM journal_lines WHERE customer_id = :c', ['c' => $c['id']])
                    + (int)DB::value('SELECT COUNT(*) FROM transaction_lines WHERE customer_id = :c', ['c' => $c['id']]);
                if ($used > 0) {
                    continue;
                }
                DB::run("DELETE FROM party_crosswalk WHERE source = 'customer' AND source_id = :i", ['i' => $c['id']]);
                DB::run('DELETE FROM customers WHERE id = :i', ['i' => $c['id']]);
                $custDeleted++;
            }
            foreach ($chunk as $pid) {
                $roles = (int)DB::value('SELECT COUNT(*) FROM customers WHERE party_id = :p', ['p' => $pid])
                    + (int)DB::value('SELECT COUNT(*) FROM vendors WHERE party_id = :p', ['p' => $pid])
                    + (int)DB::value('SELECT COUNT(*) FROM members WHERE party_id = :p', ['p' => $pid])
                    + (int)DB::value('SELECT COUNT(*) FROM other_names WHERE party_id = :p', ['p' => $pid])
                    + (int)DB::value('SELECT COUNT(*) FROM transactions WHERE party_id = :p', ['p' => $pid])
                    + (int)DB::value('SELECT COUNT(*) FROM water_accounts WHERE party_id = :p', ['p' => $pid]);
                if ($roles > 0) {
                    $partyKept++;
                    continue;
                }
                DB::run('DELETE FROM party_role_log WHERE party_id = :p', ['p' => $pid]);
                DB::run('DELETE FROM party_match_reviews WHERE party_a = :p OR party_b = :p2', ['p' => $pid, 'p2' => $pid]);
                DB::run('DELETE FROM parties WHERE id = :p', ['p' => $pid]);
                $partyDeleted++;
            }
        }
        $count('customers of water-service accounts (made by the postings)', $custDeleted);
        $count('parties of water-service accounts', $partyDeleted);
        if ($partyKept > 0) {
            $count('parties of water-service accounts KEPT (used by something else)', $partyKept);
        }
    }

    // 4. suppliers (each step below undoes only what a phase of THIS run did)
    $s = $notes['suppliers'] ?? [];
    if (isset($notes['suppliers'])) {
    $vDeleted = 0;
    foreach ($s['vendors_created'] ?? [] as $vid) {
        $used = (int)DB::value('SELECT COUNT(*) FROM transactions WHERE vendor_id = :v', ['v' => $vid]) + (int)DB::value('SELECT COUNT(*) FROM journal_lines WHERE vendor_id = :v', ['v' => $vid]);
        if ($used > 0) {
            continue;
        }
        DB::run("DELETE FROM party_crosswalk WHERE source = 'vendor' AND source_id = :i", ['i' => $vid]);
        DB::run('DELETE FROM vendors WHERE id = :i', ['i' => $vid]);
        $vDeleted++;
    }
    $count('vendors created from legacy suppliers', $vDeleted);
    $n = DB::run("DELETE FROM party_crosswalk WHERE source = 'water_supp'")->rowCount();
    $count('party_crosswalk rows (water_supp)', $n);
    $pDeleted = 0;
    foreach ($s['parties_created'] ?? [] as $pid) {
        $roles = (int)DB::value('SELECT COUNT(*) FROM customers WHERE party_id = :p', ['p' => $pid]) + (int)DB::value('SELECT COUNT(*) FROM vendors WHERE party_id = :p', ['p' => $pid])
            + (int)DB::value('SELECT COUNT(*) FROM members WHERE party_id = :p', ['p' => $pid]) + (int)DB::value('SELECT COUNT(*) FROM other_names WHERE party_id = :p', ['p' => $pid])
            + (int)DB::value('SELECT COUNT(*) FROM transactions WHERE party_id = :p', ['p' => $pid]);
        if ($roles > 0) {
            continue;
        }
        DB::run('DELETE FROM party_role_log WHERE party_id = :p', ['p' => $pid]);
        DB::run('DELETE FROM party_match_reviews WHERE party_a = :p OR party_b = :p2', ['p' => $pid, 'p2' => $pid]);
        DB::run('DELETE FROM parties WHERE id = :p', ['p' => $pid]);
        $pDeleted++;
    }
    $count('parties of legacy suppliers', $pDeleted);
    }

    // 5. users, roles, page access
    $u = $notes['users'] ?? [];
    if (isset($notes['users'])) {
    $n = 0;
    foreach ($u['overrides'] ?? [] as [$userId, $pageId]) {
        $n += DB::run('DELETE FROM user_page_overrides WHERE user_id = :u AND page_id = :p', ['u' => $userId, 'p' => $pageId])->rowCount();
    }
    $count('user page overrides', $n);
    $n = 0;
    foreach ($u['user_roles'] ?? [] as [$userId, $roleId]) {
        $n += DB::run('DELETE FROM user_roles WHERE user_id = :u AND role_id = :r', ['u' => $userId, 'r' => $roleId])->rowCount();
    }
    $count('user roles', $n);
    $n = 0;
    foreach ($u['users_created'] ?? [] as $userId) {
        $n += DB::run('DELETE FROM users WHERE id = :u AND created_via = :v', ['u' => $userId, 'v' => 'migration'])->rowCount();
    }
    $count('users created', $n);
    $n = 0;
    foreach ($u['roles_created'] ?? [] as $roleId) {
        $n += DB::run('DELETE FROM roles WHERE id = :r AND is_system = 0', ['r' => $roleId])->rowCount();
    }
    $count('roles created', $n);
    $n = DB::run('DELETE FROM water_user_map')->rowCount();
    $count('water_user_map rows', $n);
    }

    // 6. chart of accounts
    $c = $notes['coa'] ?? [];
    if (isset($notes['coa'])) {
    $n = 0;
    foreach (array_keys($c['defaults'] ?? []) as $key) {
        $n += DB::run('UPDATE default_accounts SET account_id = NULL WHERE setting_key = :k', ['k' => $key])->rowCount();
    }
    $count('water default accounts cleared', $n);
    $accountIds = array_map('intval', DB::column("SELECT account_id FROM water_coa_map WHERE how = 'imported' AND account_id IS NOT NULL"));
    if (!empty($c['clearing_created'])) {
        $accountIds[] = (int)$c['clearing_created'];
    }
    $inUse = function (int $id): bool {
        foreach (['SELECT COUNT(*) FROM journal_lines WHERE account_id = :a', 'SELECT COUNT(*) FROM transaction_lines WHERE account_id = :a',
                  'SELECT COUNT(*) FROM transactions WHERE account_id = :a OR account2_id = :a2', 'SELECT COUNT(*) FROM default_accounts WHERE account_id = :a',
                  'SELECT COUNT(*) FROM items WHERE income_account_id = :a OR cogs_account_id = :a2 OR asset_account_id = :a3 OR expense_account_id = :a4',
                  'SELECT COUNT(*) FROM users WHERE coop_cash_account_id = :a', 'SELECT COUNT(*) FROM vendors WHERE default_expense_account_id = :a'] as $sql) {
            $p = ['a' => $id];
            foreach (['a2', 'a3', 'a4'] as $k) {
                if (str_contains($sql, ':' . $k)) {
                    $p[$k] = $id;
                }
            }
            if ((int)DB::value($sql, $p) > 0) {
                return true;
            }
        }
        return false;
    };
    $aDeleted = 0;
    $aKept = 0;
    // sub-accounts first, then their parents
    $order = [];
    foreach ($accountIds as $id) {
        $order[$id] = (int)(DB::value('SELECT parent_id FROM accounts WHERE id = :a', ['a' => $id]) ?: 0) > 0 ? 0 : 1;
    }
    asort($order);
    foreach (array_keys($order) as $id) {
        if ($inUse($id) || (int)DB::value('SELECT COUNT(*) FROM accounts WHERE parent_id = :a', ['a' => $id]) > 0) {
            $aKept++;
            continue;
        }
        $aDeleted += DB::run('DELETE FROM accounts WHERE id = :a', ['a' => $id])->rowCount();
    }
    $count('accounts imported from the legacy chart', $aDeleted);
    if ($aKept > 0) {
        $count('imported accounts KEPT (they have postings or are referenced)', $aKept);
    }
    $n = DB::run('DELETE FROM water_coa_map')->rowCount();
    $count('water_coa_map rows', $n);
    Accts::reset();
    }

    // 7. classes and cooperative branches the run made
    $made = $notes['reference']['made'] ?? [];
    if (isset($notes['reference'])) {
    $n = 0;
    foreach ($made['coop_branches'] ?? [] as $id) {
        if ((int)DB::value('SELECT COUNT(*) FROM members WHERE branch_id = :b', ['b' => $id]) === 0) {
            $n += DB::run('DELETE FROM coop_branches WHERE id = :b', ['b' => $id])->rowCount();
        }
    }
    $count('cooperative branches created', $n);
    $n = 0;
    foreach ($made['classes'] ?? [] as $id) {
        $used = (int)DB::value('SELECT COUNT(*) FROM transactions WHERE class_id = :c', ['c' => $id]) + (int)DB::value('SELECT COUNT(*) FROM journal_lines WHERE class_id = :c', ['c' => $id]);
        if ($used === 0) {
            DB::run('UPDATE branches SET class_id = NULL WHERE class_id = :c', ['c' => $id]);
            DB::run('UPDATE coop_branches SET class_id = NULL WHERE class_id = :c', ['c' => $id]);
            $n += DB::run('DELETE FROM classes WHERE id = :c', ['c' => $id])->rowCount();
        }
    }
    $count('classes created', $n);
    }

    // 8. settings and the log
    if (isset($notes['history'])) {
        foreach (['water_cutover_date', 'water_source_db'] as $k) {
            Settings::set($k, '');
        }
    }
    Settings::flush();
    Audit::log('rollback', 'water', 'migration', null, 'rollback ' . $runId, ['page_key' => 'water.settings', 'snapshot' => $report, 'reason' => 'Water migration rollback of run ' . $runId]);
    if ($dryRun) {
        DB::rollBack();
        wm_out('Dry run: nothing was changed.');
    } else {
        DB::commit();
        wm_out('Rollback of run ' . $runId . ' done.');
    }
    DB::insert('water_migration_log', ['run_id' => $runId, 'phase' => 'rollback', 'status' => $dryRun ? 'dry-run' : 'done', 'rows_read' => 0,
        'rows_written' => 0, 'rows_skipped' => 0, 'notes' => json_encode(['deleted' => $report, 'by' => $adminId], JSON_UNESCAPED_UNICODE)]);
} catch (Throwable $e) {
    DB::rollBack();
    wm_err('Rollback FAILED, nothing was changed: ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
    exit(1);
}
exit(0);
