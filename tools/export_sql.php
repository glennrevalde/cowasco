<?php
declare(strict_types=1);

/**
 * Writes a single importable .sql file (schema + triggers + all data) from the current database.
 *
 *   php tools/export_sql.php                         -> database/accounting_system.sql
 *   php tools/export_sql.php --out=backup.sql        -> any file
 *   php tools/export_sql.php --db-name=coop          -> the database the file creates and fills (default: the
 *                                                       database exported, e.g. cooperative_system)
 *   php tools/export_sql.php --no-create-db          -> leave out CREATE DATABASE / USE
 *
 * The file holds every table of the program: the cooperative tables of database/schema.sql and the water billing
 * tables of database/migrations/2026_12_01_water_billing.sql (with its two compatibility views), each written in the
 * MySQL 5.5 form of those files, then the rows of every table, then the audit-trail triggers.
 *
 * The file can be imported with phpMyAdmin (Import tab) or:  mysql -u root -p < accounting_system.sql
 * Use it for backups too; importing it replaces every table.
 */
if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/lib.php';

$opts = getopt('', ['out::', 'no-create-db', 'title::', 'db-name::', 'note::']);
$out = $opts['out'] ?? (APP_ROOT . '/database/accounting_system.sql');
$name = $opts['db-name'] ?? $GLOBALS['CONFIG']['db']['name'];
$pdo = DB::pdo();

$schema = file_get_contents(APP_ROOT . '/database/schema.sql');
// the combined file controls foreign key checks itself
$schema = preg_replace('/^SET FOREIGN_KEY_CHECKS\s*=\s*[01];\s*$/mi', '', $schema);
$schema = preg_replace('/^SET NAMES utf8mb4;\s*$/mi', '', $schema);
$triggers = file_get_contents(APP_ROOT . '/database/triggers.sql');
[$waterTablesSql, $waterAlters, $waterViews, $waterTables] = water_ddl(APP_ROOT . '/database/migrations/2026_12_01_water_billing.sql');

$tables = schema_tables(APP_ROOT . '/database/schema.sql');
$all = array_merge($tables, $waterTables);
$counts = [];
foreach ($all as $t) {
    $counts[$t] = (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
}
$title = $opts['title'] ?? 'G-SMaRT Integrated Cooperative System - complete database';
$txns = $counts['transactions'] ?? 0;
$waterAccounts = $counts['water_accounts'] ?? 0;
$viewCount = (int)preg_match_all('/^CREATE\s+VIEW/im', $waterViews);

$head = "-- =====================================================================\n"
    . "--  $title\n"
    . '--  Generated ' . date('Y-m-d H:i:s P') . "\n"
    . '--  ' . count($all) . ' tables (' . count($tables) . ' cooperative, ' . count($waterTables) . ' water billing) and ' . $viewCount . " views,\n"
    . '--  ' . number_format(array_sum($counts)) . " rows ($txns ledger documents, $waterAccounts water-service accounts)\n"
    . "--\n"
    . "--  Import:  phpMyAdmin > Import > choose this file > Go\n"
    . "--      or:  mysql -u root -p < " . basename($out) . "\n"
    . "--  Works on MySQL 5.5.3 or newer (5.5, 5.6, 5.7, 8.x) and MariaDB 5.5 or newer.\n"
    . "--  Import as root (or a user with CREATE, CREATE VIEW, INSERT, REFERENCES and TRIGGER privileges).\n"
    . "--  WARNING: importing drops and recreates every table in the database below.\n"
    . "--\n"
    . "--  Default login: admin / Admin@12345 (a new password is required at first login)\n";
foreach (array_filter(explode("\n", (string)($opts['note'] ?? ''))) as $line) {
    $head .= '--  ' . $line . "\n";
}
$head .= "-- =====================================================================\n\n"
    . "SET NAMES utf8mb4;\n"
    . "SET FOREIGN_KEY_CHECKS = 0;\n"
    . "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n"
    . "SET time_zone = '" . date('P') . "';\n\n";

if (!isset($opts['no-create-db'])) {
    $head .= "CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n"
        . "USE `$name`;\n\n";
}

$sql = $head
    . "-- ---------------------------------------------------------------------\n"
    . "--  1. TABLES\n"
    . "-- ---------------------------------------------------------------------\n"
    . trim($schema) . "\n\n"
    . "-- ---------------------------------------------------------------------\n"
    . "--  2. WATER BILLING TABLES (database/migrations/2026_12_01_water_billing.sql)\n"
    . "-- ---------------------------------------------------------------------\n"
    . $waterTablesSql . "\n"
    . "-- the integration columns of the water tables (added only where missing)\n"
    . $waterAlters . "\n"
    . "-- the two compatibility views the water pages read (the chart of accounts and the operators)\n"
    . $waterViews . "\n"
    . "-- ---------------------------------------------------------------------\n"
    . "--  3. DATA\n"
    . "-- ---------------------------------------------------------------------\n"
    . export_data($pdo, $all) . "\n"
    . "-- ---------------------------------------------------------------------\n"
    . "--  4. AUDIT TRAIL PROTECTION (created after the data is loaded)\n"
    . trim($triggers) . "\n\n"
    . "SET FOREIGN_KEY_CHECKS = 1;\n";

file_put_contents($out, $sql);
out(sprintf('Wrote %s (%s KB, %d tables, %d views, %d rows).', $out, number_format(strlen($sql) / 1024), count($all), $viewCount, array_sum($counts)));

/**
 * The structure part of the water billing migration: each CREATE TABLE (preceded by DROP TABLE IF EXISTS, so an
 * import replaces the table), the conditional ALTERs that add the integration columns, and the two views. The rows
 * the migration writes to the cooperative tables (menu, page rights, settings, line of business) are left out: the
 * data section carries them as they are in the database exported.
 *
 * @return array{0:string,1:string,2:string,3:string[]} tables SQL, alters SQL, views SQL, table names
 */
function water_ddl(string $file): array
{
    $tables = $alters = $views = '';
    $names = [];
    foreach (Migrator::split((string)file_get_contents($file)) as $stmt) {
        $s = trim($stmt);
        if (preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?/i', $s, $m)) {
            $names[] = $m[1];
            $tables .= "DROP TABLE IF EXISTS `{$m[1]}`;\n"
                . preg_replace('/^CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+/i', 'CREATE TABLE ', $s) . ";\n\n";
        } elseif (preg_match('/^SET\s+@sql\s*=/i', $s) && preg_match('/ALTER\s+TABLE/i', $s)) {
            $alters .= $s . ";\n";
        } elseif (preg_match('/^(PREPARE\s+s\s+FROM\s+@sql|EXECUTE\s+s|DEALLOCATE\s+PREPARE\s+s)$/i', $s)) {
            $alters .= $s . ";\n";
        } elseif (preg_match('/^(DROP|CREATE)\s+VIEW\b/i', $s)) {
            $views .= $s . ";\n";
        }
        // anything else (SET NAMES, foreign-key switches, variables, INSERT / UPDATE of the cooperative tables) is
        // not structure of the water tables
    }
    if (count($names) < 50 || $views === '') {
        throw new RuntimeException("The water billing tables were not found in $file.");
    }
    return [$tables, $alters, $views, $names];
}
