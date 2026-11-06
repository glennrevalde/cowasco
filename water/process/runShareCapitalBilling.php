<?php
/**
 * CLI runner of the Fixed Share Capital Billing: posts the bills whose posting day has come.
 *
 * Windows Task Scheduler example (daily, early morning):
 *   php process/runShareCapitalBilling.php
 *   php process/runShareCapitalBilling.php --date=2026-11-30
 *
 * Posting a bill makes no journal entry: it only records the contribution expected from the member. The cashier
 * collects it (the deposit to the share capital account and its journal entry are made then). The bills are also
 * posted when Fixed Share Capital Billing or the cashier opens, so this runner is optional; it is safe to run any
 * number of times (one bill per member billing and posting date).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("This command is available only from the command line.\n");
}

require_once __DIR__ . '/../database/config.php';

$options = getopt('', ['date::']);
$runDate = trim((string)($options['date'] ?? date('Y-m-d')));
if (!valid_date($runDate)) {
    fwrite(STDERR, "Invalid --date value. Use YYYY-MM-DD.\n");
    exit(2);
}
if ($runDate > date('Y-m-d')) {
    fwrite(STDERR, "--date cannot be after today: a bill is posted on its posting day, not before.\n");
    exit(2);
}

try {
    if (!ShareCapitalBilling::ready()) {
        throw new RuntimeException('Run the database update database/migrations/2027_03_14_share_capital_billing.sql first.');
    }
    $n = ShareCapitalBilling::postDue($runDate);
    fwrite(STDOUT, sprintf("[%s] Fixed share capital billing: %d bill(s) posted up to %s.\n", date('Y-m-d H:i:s'), $n, $runDate));
    exit(0);
} catch (Throwable $e) {
    error_log('[runShareCapitalBilling] ' . $e->getMessage());
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] Fixed share capital billing failed: ' . $e->getMessage() . "\n");
    exit(1);
}
