<?php
declare(strict_types=1);

/**
 * Builds the target database of the integrated system from the cooperative database in use, leaving that database
 * untouched: step 1 of the cut-over (step 2 is tools/migrate_water.php, which brings in the water-billing database).
 *
 *   php tools/create_target.php --coop-source=cooperative_system --target=coop_water
 *   php tools/create_target.php --coop-source=cooperative_system --target=coop_water --drop    (rebuild the target)
 *
 * 1. checks: the source is a database of this system, the target is a different name and does not exist yet
 *    (--drop deletes and rebuilds a target that exists, never the source);
 * 2. copies every table (structure and rows), view, stored routine and trigger of the source into the target, with
 *    the foreign-key checks off and NO_AUTO_VALUE_ON_ZERO so every id is kept as it is;
 * 3. compares the two, table by table: row counts and CHECKSUM TABLE must be equal;
 * 4. runs the upgrade scripts the copy still lacks (database/migrations, oldest first; the water-billing module is
 *    the last of them), exactly what the System Updates page would run;
 * 5. writes a report to storage/migration/create_target_<target>_<time>.md.
 *
 * MySQL 5.5 compatible: SHOW CREATE TABLE / VIEW / PROCEDURE / FUNCTION / TRIGGER and INSERT ... SELECT only.
 * The database user needs CREATE, DROP, SELECT, INSERT, CREATE VIEW, CREATE ROUTINE and TRIGGER on both databases
 * (root on XAMPP has them). Credentials come from config/config.php (or DB_HOST, DB_PORT, DB_USER, DB_PASS).
 */
if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}

$opts = getopt('', ['coop-source:', 'target:', 'drop', 'skip-migrations', 'help']);
if (isset($opts['help']) || empty($opts['coop-source']) || empty($opts['target'])) {
    fwrite(STDERR, "usage: php tools/create_target.php --coop-source=<cooperative database> --target=<new database> [--drop] [--skip-migrations]\n");
    exit(2);
}
$source = (string)$opts['coop-source'];
$target = (string)$opts['target'];
// the program works on the target from here on (Migrator, Schema)
putenv('DB_NAME=' . $target);
$_ENV['DB_NAME'] = $target;
require __DIR__ . '/../app/bootstrap.php';

function ct_out(string $s): void
{
    echo $s . "\n";
}

function ct_fail(string $s): never
{
    fwrite(STDERR, "STOPPED: $s\n");
    exit(1);
}

if (!DB::validName($source) || !DB::validName($target)) {
    ct_fail('Database names may hold letters, digits and underscores only.');
}
if (strcasecmp($source, $target) === 0) {
    ct_fail('The target must be a new database, not the cooperative database itself.');
}
$server = DB::connect(null);
$server->exec("SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO,NO_ENGINE_SUBSTITUTION'");
$exists = fn(string $db) => (int)$server->query('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = ' . $server->quote($db))->fetchColumn() > 0;
if (!$exists($source)) {
    ct_fail("The cooperative database `$source` does not exist on this server.");
}
$isApp = (int)$server->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = " . $server->quote($source) . " AND table_name IN ('users', 'accounts', 'transactions', 'journal_lines')")->fetchColumn();
if ($isApp < 4) {
    ct_fail("`$source` is not a database of this system (users, accounts, transactions and journal_lines are expected).");
}
if ($exists($target)) {
    if (!isset($opts['drop'])) {
        ct_fail("`$target` exists already. Choose another name, or add --drop to delete and rebuild it.");
    }
    ct_out("Dropping the old target `$target` ...");
    $server->exec("DROP DATABASE `$target`");
}

@mkdir(APP_ROOT . '/storage/migration', 0775, true);
$started = microtime(true);
$report = [];
$report[] = '# Target database created from the cooperative database';
$report[] = '';
$report[] = '- **Source (unchanged):** `' . $source . '`';
$report[] = '- **Target:** `' . $target . '`';
$report[] = '- **Started:** ' . date('Y-m-d H:i:s');
$report[] = '- **Server:** ' . $server->query('SELECT VERSION()')->fetchColumn();
$report[] = '';

$cs = $server->query('SELECT default_character_set_name, default_collation_name FROM information_schema.schemata WHERE schema_name = ' . $server->quote($source))->fetch();
$server->exec("CREATE DATABASE `$target` CHARACTER SET {$cs['default_character_set_name']} COLLATE {$cs['default_collation_name']}");
ct_out("Created `$target` ({$cs['default_character_set_name']}).");

// ------------------------------------------------------------------ tables
$q = fn(string $sql) => $server->query($sql);
$tables = $q("SELECT table_name FROM information_schema.tables WHERE table_schema = " . $server->quote($source) . " AND table_type = 'BASE TABLE' ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
$server->exec('SET FOREIGN_KEY_CHECKS = 0');
$server->exec("USE `$target`");
$copied = [];
foreach ($tables as $t) {
    $ddl = (string)$q("SHOW CREATE TABLE `$source`.`$t`")->fetch()['Create Table'];
    $server->exec($ddl);
    $cols = $q("SELECT column_name FROM information_schema.columns WHERE table_schema = " . $server->quote($source) . " AND table_name = " . $server->quote($t) . " ORDER BY ordinal_position")->fetchAll(PDO::FETCH_COLUMN);
    $list = '`' . implode('`, `', $cols) . '`';
    $n = $server->exec("INSERT INTO `$target`.`$t` ($list) SELECT $list FROM `$source`.`$t`");
    $copied[$t] = (int)$n;
}
ct_out('Copied ' . count($tables) . ' table(s), ' . array_sum($copied) . ' row(s).');

// ------------------------------------------------------------------ views (in dependency order: retried until all are made)
$strip = function (string $ddl) use ($source): string {
    $ddl = (string)preg_replace('/\bDEFINER\s*=\s*(`[^`]*`|\S+)@(`[^`]*`|\S+)\s*/i', '', $ddl);
    return str_replace(['`' . $source . '`.', $source . '.'], '', $ddl);
};
$views = $q("SELECT table_name FROM information_schema.views WHERE table_schema = " . $server->quote($source) . " ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
$todo = $views;
$viewErrors = [];
for ($round = 0; $todo && $round < 10; $round++) {
    $left = [];
    foreach ($todo as $v) {
        $ddl = $strip((string)$q("SHOW CREATE VIEW `$source`.`$v`")->fetch()['Create View']);
        try {
            $server->exec($ddl);
        } catch (Throwable $e) {
            $left[] = $v;
            $viewErrors[$v] = $e->getMessage();
        }
    }
    $todo = $left;
}
if ($todo) {
    ct_fail('These views could not be created in the target: ' . implode(', ', array_map(fn($v) => "$v ({$viewErrors[$v]})", $todo)));
}
ct_out('Created ' . count($views) . ' view(s).');

// ------------------------------------------------------------------ stored routines and triggers (after the rows, so no trigger fires on the copy)
$routines = $q("SELECT routine_type, routine_name FROM information_schema.routines WHERE routine_schema = " . $server->quote($source))->fetchAll();
foreach ($routines as $r) {
    $kind = strtoupper((string)$r['routine_type']);
    $row = $q("SHOW CREATE $kind `$source`.`{$r['routine_name']}`")->fetch();
    $ddl = (string)($row['Create Procedure'] ?? $row['Create Function'] ?? '');
    if ($ddl === '') {
        ct_fail("The definition of $kind {$r['routine_name']} cannot be read (the database user needs the right to see it).");
    }
    $server->exec($strip($ddl));
}
$triggers = $q("SELECT trigger_name FROM information_schema.triggers WHERE trigger_schema = " . $server->quote($source))->fetchAll(PDO::FETCH_COLUMN);
foreach ($triggers as $tr) {
    $row = $q("SHOW CREATE TRIGGER `$source`.`$tr`")->fetch();
    $server->exec($strip((string)$row['SQL Original Statement']));
}
$server->exec('SET FOREIGN_KEY_CHECKS = 1');
ct_out('Created ' . count($routines) . ' routine(s) and ' . count($triggers) . ' trigger(s).');
$events = (int)$q("SELECT COUNT(*) FROM information_schema.events WHERE event_schema = " . $server->quote($source))->fetchColumn();

// ------------------------------------------------------------------ the copy is the same as the source
$diff = [];
$report[] = '## 1. Copy of the cooperative database';
$report[] = '';
$report[] = '| Table | Rows in source | Rows in target | Checksum equal |';
$report[] = '|---|---:|---:|---|';
foreach ($tables as $t) {
    $a = (int)$q("SELECT COUNT(*) FROM `$source`.`$t`")->fetchColumn();
    $b = (int)$q("SELECT COUNT(*) FROM `$target`.`$t`")->fetchColumn();
    $ca = $q("CHECKSUM TABLE `$source`.`$t`")->fetch()['Checksum'] ?? null;
    $cb = $q("CHECKSUM TABLE `$target`.`$t`")->fetch()['Checksum'] ?? null;
    $same = $a === $b && (string)$ca === (string)$cb;
    if (!$same) {
        $diff[] = $t;
    }
    $report[] = "| $t | $a | $b | " . ($same ? 'yes' : '**NO**') . ' |';
}
$report[] = '';
$report[] = '- Views: ' . count($views) . ' (' . (implode(', ', $views) ?: 'none') . ')';
$report[] = '- Stored routines: ' . count($routines) . '; triggers: ' . count($triggers) . ($events ? "; events not copied: $events (create them again by hand)" : '');
$report[] = '';
if ($diff) {
    file_put_contents(APP_ROOT . '/storage/migration/create_target_' . $target . '_' . date('Ymd_His') . '.md', implode("\n", $report) . "\n");
    ct_fail('The copy differs from the source in: ' . implode(', ', $diff) . '. Nothing was migrated; drop the target and try again.');
}
ct_out('Verified: every table has the same rows and checksum as the source.');

// ------------------------------------------------------------------ upgrade scripts still to run on the copy
$report[] = '## 2. Upgrade scripts run on the target';
$report[] = '';
if (isset($opts['skip-migrations'])) {
    $report[] = 'Skipped (--skip-migrations).';
    ct_out('Upgrade scripts skipped.');
} else {
    $db = Sql::to($target);
    $pending = Schema::pendingOn($db);
    ct_out($pending ? 'Running ' . count($pending) . ' upgrade script(s): ' . implode(', ', array_map('basename', $pending)) : 'No upgrade script is pending.');
    $r = Migrator::runPending($db);
    foreach ($r['ran'] as $f) {
        $report[] = '- ran `' . $f . '`';
    }
    if (!$r['ran']) {
        $report[] = '- none was pending';
    }
    if ($r['errors']) {
        foreach ($r['errors'] as $e) {
            $report[] = '- **error:** ' . $e;
        }
        file_put_contents(APP_ROOT . '/storage/migration/create_target_' . $target . '_' . date('Ymd_His') . '.md', implode("\n", $report) . "\n");
        ct_fail('An upgrade script failed: ' . implode('; ', $r['errors']));
    }
    $still = Schema::pendingOn($db);
    if ($still) {
        ct_fail('Still pending after the run: ' . implode(', ', $still));
    }
    // the upgrade added tables; nothing that was copied may have lost a row
    $lost = [];
    foreach ($copied as $t => $n) {
        $now = (int)$q("SELECT COUNT(*) FROM `$target`.`$t`")->fetchColumn();
        if ($now < (int)$q("SELECT COUNT(*) FROM `$source`.`$t`")->fetchColumn()) {
            $lost[] = $t;
        }
    }
    if ($lost) {
        ct_fail('Rows were lost by an upgrade script in: ' . implode(', ', $lost));
    }
    $report[] = '';
    $report[] = 'Every table copied from the source still has at least its rows after the upgrade.';
    ct_out('Upgrade scripts done; the target is at the program\'s version.');
}

$report[] = '';
$report[] = '## 3. Next step';
$report[] = '';
$report[] = 'Bring in the water-billing database (dry run first):';
$report[] = '';
$report[] = '```';
$report[] = "DB_NAME=$target php tools/migrate_water.php --source=<water database> --cutover=YYYY-MM-DD --dry-run";
$report[] = "DB_NAME=$target php tools/migrate_water.php --source=<water database> --cutover=YYYY-MM-DD";
$report[] = '```';
$report[] = '';
$report[] = sprintf('Finished %s in %.1f s.', date('Y-m-d H:i:s'), microtime(true) - $started);
@mkdir(APP_ROOT . '/storage/migration', 0775, true);
$file = APP_ROOT . '/storage/migration/create_target_' . $target . '_' . date('Ymd_His') . '.md';
file_put_contents($file, implode("\n", $report) . "\n");
ct_out('Report: ' . $file);
ct_out("Done. Next: DB_NAME=$target php tools/migrate_water.php --source=<water database> --cutover=YYYY-MM-DD --dry-run");
