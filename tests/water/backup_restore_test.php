<?php
declare(strict_types=1);

/**
 * Backup and restore of the integrated database (acceptance 7.4 "restore"): a backup made by the program
 * (System Administration > Backup & Restore) is read back into an empty database, which must then be the same.
 *
 *   DB_NAME=<integrated database> php tests/water/backup_restore_test.php [--restore-into=<scratch database>] [--out=file.md]
 *
 * Checks: every table has the same row count and CHECKSUM TABLE as the original (the backup and restore logs
 * excepted: the restore writes to them), the water compatibility views and the audit-trail triggers are back, no
 * upgrade script is reported pending, and the ledger still balances. The scratch database is dropped and made again.
 */
if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}
$opts = getopt('', ['restore-into:', 'out:']);
require __DIR__ . '/../../app/bootstrap.php';
require __DIR__ . '/e2e_lib.php';
$_SESSION = [];
Tenant::bootCli();
Auth::actAs((int)DB::value("SELECT id FROM users WHERE username = 'admin'"));

$source = DB::databaseName();
$scratch = (string)($opts['restore-into'] ?? ($source . '_restored'));
if (!DB::validName($scratch) || $scratch === $source) {
    exit("Choose another scratch database name.\n");
}
$started = microtime(true);
$skip = ['backup_log', 'audit_trail'];

Checks::section('Backup of ' . $source);
$b = Backup::create('Acceptance test: backup and restore', false, true, 'acceptance_');
Checks::true('the backup file is written (' . $b['file'] . ', ' . number_format($b['size']) . ' bytes)', is_file($b['path']) && $b['size'] > 0);
Checks::eq('every table is in the backup', count(Backup::tables()), $b['tables']);

$server = DB::connect(null);
$server->exec("DROP DATABASE IF EXISTS `$scratch`");
$server->exec("CREATE DATABASE `$scratch` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

Checks::section('Restore into the empty database ' . $scratch);
DB::useDatabase($scratch);
$r = Backup::restore($b['path'], false);
Checks::eq('the restore ran without a failed statement', '', implode(' | ', array_slice($r['errors'], 0, 3)));
Checks::true('statements run: ' . number_format($r['statements']), $r['statements'] > 0);

$tablesA = $server->query("SELECT table_name FROM information_schema.tables WHERE table_schema = " . $server->quote($source) . " AND table_type = 'BASE TABLE' ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
$tablesB = $server->query("SELECT table_name FROM information_schema.tables WHERE table_schema = " . $server->quote($scratch) . " AND table_type = 'BASE TABLE' ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
Checks::eq('the same tables (' . count($tablesA) . ')', implode(', ', $tablesA), implode(', ', $tablesB));
$rowsDiff = [];
$sumDiff = [];
$rows = 0;
foreach ($tablesA as $t) {
    if (in_array($t, $skip, true)) {
        continue;
    }
    $a = (int)$server->query("SELECT COUNT(*) FROM `$source`.`$t`")->fetchColumn();
    $bb = (int)$server->query("SELECT COUNT(*) FROM `$scratch`.`$t`")->fetchColumn();
    $rows += $a;
    if ($a !== $bb) {
        $rowsDiff[] = "$t ($a/$bb)";
    }
    $ca = (string)($server->query("CHECKSUM TABLE `$source`.`$t`")->fetch()['Checksum'] ?? '');
    $cb = (string)($server->query("CHECKSUM TABLE `$scratch`.`$t`")->fetch()['Checksum'] ?? '');
    if ($ca !== $cb) {
        $sumDiff[] = $t;
    }
}
Checks::eq('row counts equal in every table (' . number_format($rows) . ' rows)', '', implode(', ', $rowsDiff));
Checks::eq('table checksums equal', '', implode(', ', $sumDiff));
Checks::eq('the water compatibility views are back', '', implode(', ', WaterSchema::missingViews(DB::pdo())));
Checks::eq('the audit-trail triggers are back', 2, (int)DB::value("SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = DATABASE() AND event_object_table = 'audit_trail'"));
Schema::flush();
Checks::eq('no upgrade script is reported pending on the restored database', '', implode(', ', Schema::pendingOn(Sql::to($scratch))));
Checks::eq('the ledger balances (debits = credits)', '0.00', number_format((float)DB::value('SELECT COALESCE(SUM(debit - credit), 0) FROM journal_lines'), 2));
Checks::eq('the water postings came back with their documents', (int)$server->query("SELECT COUNT(*) FROM `$source`.water_postings")->fetchColumn(), (int)DB::value('SELECT COUNT(*) FROM water_postings w JOIN transactions t ON t.id = w.txn_id'));

Checks::section('A backup imported by hand (tables only): the system asks for the water migration, which is safe to run again');
DB::run('DROP VIEW IF EXISTS chartofaccntstbl');
DB::run('DROP VIEW IF EXISTS operator');
Schema::flush();
$pending = Schema::pendingOn(Sql::to($scratch));
Checks::eq('without its views the water migration is reported pending', WaterSchema::MIGRATION, implode(', ', $pending));
$countsBefore = [];
foreach (['water_accounts', 'bill_readings', 'payment_transactions', 'transactions', 'journal_lines', 'parties', 'pages', 'role_page_access', 'water_postings'] as $t) {
    $countsBefore[$t] = (int)DB::value("SELECT COUNT(*) FROM `$t`");
}
$run = Migrator::runPending(Sql::to($scratch));
Checks::eq('running it again succeeds', '', implode(' | ', $run['errors']));
Checks::eq('the views are made', '', implode(', ', WaterSchema::missingViews(DB::pdo())));
$changed = [];
foreach ($countsBefore as $t => $n) {
    if ((int)DB::value("SELECT COUNT(*) FROM `$t`") !== $n) {
        $changed[] = $t;
    }
}
Checks::eq('...and no data changes (accounts, bills, receipts, documents, parties, page rights)', '', implode(', ', $changed));
Checks::eq('the ledger still balances', '0.00', number_format((float)DB::value('SELECT COALESCE(SUM(debit - credit), 0) FROM journal_lines'), 2));

$secs = round(microtime(true) - $started, 1);
$total = count(Checks::$rows);
$md = Checks::markdown('Backup and restore test', ['Run' => date('Y-m-d H:i:s') . " ($secs s)", 'Original' => $source, 'Restored into' => $scratch,
    'Environment' => 'PHP ' . PHP_VERSION . ', ' . DB::value('SELECT VERSION()')]);
$out = (string)($opts['out'] ?? (__DIR__ . '/RESULTS_BACKUP_RESTORE.md'));
file_put_contents($out, $md);
echo "\n" . ($total - Checks::$fail) . ' passed, ' . Checks::$fail . " failed, $total checks ({$secs}s). Written to $out\n";
@unlink($b['path']);
exit(Checks::$fail ? 1 : 0);
