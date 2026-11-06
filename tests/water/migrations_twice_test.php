<?php
declare(strict_types=1);

/**
 * Every upgrade script run a second time on a database that already has it (acceptance 7.4 "migrations twice").
 *
 *   DB_NAME=<scratch copy of an integrated database> php tests/water/migrations_twice_test.php [--out=file.md]
 *
 * Use a copy (tools/create_target.php --coop-source=<integrated db> --target=<scratch> --skip-migrations): the
 * scripts are run again on it, oldest first, exactly as the System Updates page runs them. Expected: no script
 * fails, nothing is reported pending afterwards, no table loses or gains a row (the audit and log tables
 * excepted), and the ledger is unchanged.
 */
if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}
$opts = getopt('', ['out:']);
require __DIR__ . '/../../app/bootstrap.php';
require __DIR__ . '/e2e_lib.php';
$_SESSION = [];
Tenant::bootCli();
$db = Sql::to(DB::databaseName());
$started = microtime(true);
$skipTables = ['audit_trail', 'backup_log', 'water_migration_log'];

$counts = function () use ($db, $skipTables): array {
    $out = [];
    foreach ($db->column("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name") as $t) {
        if (!in_array($t, $skipTables, true)) {
            $out[$t] = (int)$db->value("SELECT COUNT(*) FROM `$t`");
        }
    }
    return $out;
};
$ledger = fn() => $db->value('SELECT CONCAT(COUNT(*), \'/\', COALESCE(SUM(debit), 0), \'/\', COALESCE(SUM(credit), 0)) FROM journal_lines');

Checks::section('Before: ' . DB::databaseName());
Checks::eq('every applied script is recognised as applied, so none is offered to run again', '', implode(', ', Schema::pendingOn($db)));
$before = $counts();
$ledgerBefore = $ledger();
Checks::true('tables counted: ' . count($before) . ', rows: ' . number_format(array_sum($before)), count($before) > 0);

// the water module's own script first: run again, it must change nothing at all
Checks::section('The water-billing upgrade script run again: nothing changes');
$runFile = function (string $file) use ($db): string {
    try {
        foreach (Migrator::split((string)file_get_contents(APP_ROOT . '/' . $file)) as $stmt) {
            $db->exec($stmt);
        }
        return '';
    } catch (Throwable $e) {
        return mb_substr($e->getMessage(), 0, 150);
    }
};
$diffOf = function (array $a, array $b): string {
    $out = [];
    foreach ($a as $t => $n) {
        if (($b[$t] ?? -1) !== $n) {
            $out[] = "$t ($n -> " . ($b[$t] ?? 'gone') . ')';
        }
    }
    return implode(', ', $out);
};
Checks::eq(basename(WaterSchema::MIGRATION) . ' runs again without an error', '', $runFile(WaterSchema::MIGRATION));
$afterWater = $counts();
Checks::eq('no table gained or lost a row', '', $diffOf($before, $afterWater));
Checks::eq('the ledger is unchanged (lines/debits/credits)', $ledgerBefore, $ledger());
Checks::eq('the water views are there', '', implode(', ', WaterSchema::missingViews(DB::pdo())));
$secondRun = $counts();
Checks::eq('...and a third run changes nothing either', '', $runFile(WaterSchema::MIGRATION) . $diffOf($secondRun, $counts()));

// the scripts of the cooperative system before it: each one runs again without an error and leaves the data as it
// was; some give page rights again by their rule ("the roles that may open X also get Y"), which is listed
Checks::section('The earlier upgrade scripts run again (sample-data scripts excluded)');
$rightsOnly = [];
foreach (Schema::files() as $file) {
    if (str_contains($file, 'sample_data') || $file === WaterSchema::MIGRATION) {
        continue;
    }
    $was = $counts();
    $err = $runFile($file);
    $now = $counts();
    $changed = $diffOf($was, $now);
    $other = trim(preg_replace('/role_page_access \([^)]*\)(, )?/', '', $changed), ', ');
    Checks::eq(basename($file) . ': no error, no data row changed', '', $err . $other);
    if ($changed !== '' && $other === '') {
        $rightsOnly[] = basename($file) . ' ' . $changed;
    }
}

Checks::section('After');
Schema::flush();
Checks::eq('still nothing pending', '', implode(', ', Schema::pendingOn($db)));
Checks::eq('the ledger is unchanged (lines/debits/credits)', $ledgerBefore, $ledger());
Checks::eq('page rights given again by the earlier scripts\' rules (informational: the system never runs an applied script again, see the first check)',
    'listed', $rightsOnly ? implode('; ', $rightsOnly) : 'none', true);

$secs = round(microtime(true) - $started, 1);
$total = count(Checks::$rows);
$md = Checks::markdown('Upgrade scripts run twice', ['Run' => date('Y-m-d H:i:s') . " ($secs s)", 'Database' => DB::databaseName(),
    'Environment' => 'PHP ' . PHP_VERSION . ', ' . DB::value('SELECT VERSION()')]);
$out = (string)($opts['out'] ?? (__DIR__ . '/RESULTS_MIGRATIONS_TWICE.md'));
file_put_contents($out, $md);
echo "\n" . ($total - Checks::$fail) . ' passed, ' . Checks::$fail . " failed, $total checks ({$secs}s). Written to $out\n";
exit(Checks::$fail ? 1 : 0);
