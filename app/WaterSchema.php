<?php
declare(strict_types=1);

/**
 * Database objects of the water module that are not tables.
 *
 * The water pages read two compatibility views (chartofaccntstbl over the chart of accounts, operator over the user
 * accounts). A backup holds tables only, and a restore into a company may not create views (Backup::refused), so the
 * restore puts them back from their one definition, the water migration file, the way it puts back the audit-trail
 * triggers. A database imported by hand without them shows the water migration as still to be run (Schema), and
 * running that migration again is safe and creates them.
 */
final class WaterSchema
{
    public const MIGRATION = 'database/migrations/2026_12_01_water_billing.sql';
    public const VIEWS = ['chartofaccntstbl', 'operator'];

    /** True when this database has the water module (its tables are there) */
    public static function installed(?PDO $pdo = null): bool
    {
        $pdo ??= DB::pdo();
        return (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'water_accounts'")->fetchColumn() > 0;
    }

    /** The views the water module needs that this database lacks */
    public static function missingViews(?PDO $pdo = null): array
    {
        $pdo ??= DB::pdo();
        $have = $pdo->query("SELECT table_name FROM information_schema.views WHERE table_schema = DATABASE()")->fetchAll(PDO::FETCH_COLUMN);
        return array_values(array_diff(self::VIEWS, array_map('strtolower', $have)));
    }

    /**
     * Creates the compatibility views again from the migration file (always all of them, so a view that was changed
     * by hand is put right too). Returns '' when done, or what went wrong. Does nothing without the water module.
     */
    public static function ensureViews(?PDO $pdo = null): string
    {
        $pdo ??= DB::pdo();
        try {
            if (!self::installed($pdo)) {
                return '';
            }
            $sql = @file_get_contents(APP_ROOT . '/' . self::MIGRATION);
            if ($sql === false) {
                return self::MIGRATION . ' is missing from the program folder.';
            }
            $n = 0;
            foreach (Migrator::split($sql) as $stmt) {
                $bare = ltrim((string)preg_replace('/^(\s*--[^\n]*\n)+/', '', $stmt));
                if (preg_match('/^(DROP\s+VIEW\s+IF\s+EXISTS|CREATE\s+VIEW)\s+`?(\w+)`?/i', $bare, $m) && in_array(strtolower($m[2]), self::VIEWS, true)) {
                    $pdo->exec($bare);
                    $n++;
                }
            }
            if ($n < 2 * count(self::VIEWS)) {
                return 'The view definitions were not found in ' . self::MIGRATION . '.';
            }
            $missing = self::missingViews($pdo);
            return $missing ? 'Still missing: ' . implode(', ', $missing) . '.' : '';
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }
}
