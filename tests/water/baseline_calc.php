<?php
declare(strict_types=1);

/**
 * Water bill calculation, before and after the integration.
 *
 * Prints one JSON line per (water-service account, consumption): the amounts compute_water_billing_details()
 * gives (base charge, discount, amount due, the income / A/R breakdown). Run it once with the FORMER program's
 * code against the FORMER database and once with this system against the migrated database, then compare the two
 * files with tests/water/compare_baseline.php. Nothing is written to either database.
 *
 *   php tests/water/baseline_calc.php --water-root=<folder with common/, process/, database/config.php>
 *        [--limit=N] [--accounts=all|active] > out.jsonl
 *
 * The --water-root of this system is its own water/ folder (the default).
 */
if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}
$opts = getopt('', ['water-root:', 'limit:', 'accounts:']);
$root = rtrim((string)($opts['water-root'] ?? (__DIR__ . '/../../water')), '/');
$limit = (int)($opts['limit'] ?? 0);

require $root . '/database/config.php';
require_once $root . '/common/billingHelpers.php';
require_once $root . '/common/waterRateBillingHelpers.php';

$pdo = getDB();
// the integrated system keeps the former members table as water_accounts
$table = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'water_accounts'")->fetchColumn() > 0
    ? 'water_accounts' : 'members';
$sql = "SELECT pkey FROM `$table` WHERE " . (($opts['accounts'] ?? 'all') === 'active' ? "status = 'A'" : '1 = 1') . ' ORDER BY pkey' . ($limit > 0 ? ' LIMIT ' . $limit : '');
$ids = array_map('intval', $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN));

$volumes = [0, 1, 2, 5, 9, 10, 11, 12, 15, 19, 20, 21, 25, 30, 31, 35, 40, 50, 51, 75, 100, 101, 150, 250, 1000];
foreach ($ids as $id) {
    foreach ($volumes as $v) {
        $row = ['account' => $id, 'cons' => $v];
        try {
            $d = compute_water_billing_details($pdo, $id, (float)$v, true);
            $row += [
                'ok' => true,
                'base' => number_format((float)($d['base_charge'] ?? 0), 2, '.', ''),
                'discount' => number_format((float)($d['discount_amount'] ?? 0), 2, '.', ''),
                'due' => number_format((float)($d['amount_due'] ?? 0), 2, '.', ''),
                'billable' => (int)($d['billable_consumption'] ?? $d['billable_volume'] ?? -1),
                'rc' => (int)($d['rc_id'] ?? 0),
                'pipe' => (int)($d['pipe_size_id'] ?? 0),
                // the account ids differ between the two databases; the amounts per line are compared in order
                'income' => array_map(fn($l) => number_format((float)$l['amount'], 2, '.', ''), $d['income_lines'] ?? []),
                'ar' => array_map(fn($l) => number_format((float)$l['amount'], 2, '.', ''), $d['ar_lines'] ?? []),
                'income_acct' => array_map(fn($l) => (int)$l['account_id'], $d['income_lines'] ?? []),
                'ar_acct' => array_map(fn($l) => (int)$l['account_id'], $d['ar_lines'] ?? []),
            ];
        } catch (Throwable $e) {
            $row += ['ok' => false, 'error' => $e->getMessage()];
        }
        echo json_encode($row, JSON_UNESCAPED_SLASHES), "\n";
    }
}
