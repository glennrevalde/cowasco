<?php
declare(strict_types=1);

/**
 * Runs the upgrade scripts the database still lacks (database/migrations, oldest first): the same files the page
 * after login names when an upgrade is pending, and the same runner the platform's System Updates page uses.
 *
 *   php tools/upgrade.php            (the database of config/config.php, or DB_NAME)
 *   php tools/upgrade.php --list     (only lists what is pending)
 *
 * Every script is written to be run again safely. Back up the database first.
 */
if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}
require __DIR__ . '/../app/bootstrap.php';
$db = Sql::to(DB::databaseName());
$pending = Schema::pendingOn($db);
echo 'Database ' . DB::databaseName() . ': ' . ($pending ? count($pending) . ' upgrade script(s) pending: ' . implode(', ', array_map('basename', $pending)) : 'up to date') . "\n";
if (!$pending || in_array('--list', $argv, true)) {
    exit(0);
}
$r = Migrator::runPending($db);
foreach ($r['ran'] as $f) {
    echo "  ran $f\n";
}
foreach ($r['errors'] as $e) {
    echo "  ERROR $e\n";
}
Schema::flush();
$still = Schema::pendingOn($db);
echo $still ? 'Still pending: ' . implode(', ', $still) . "\n" : "Done: the database is at the program's version.\n";
exit($r['errors'] || $still ? 1 : 0);
