<?php
/**
 * CLI recurring-billing runner.
 *
 * Windows Task Scheduler example:
 *   php process/runRecurringBilling.php --operator=1 --class=2
 *
 * The operator must be a valid active operator whose ID is used for the
 * generated bills and journal audit trail. This script never runs through a
 * browser and does not depend on a web session.
 *
 * --class is the class of the bills (Chart of Accounts > Class List, the same classes as the accounting books):
 * every bill and journal entry of the run carries it. Without it the bills take the water branch's class.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("This command is available only from the command line.
");
}

require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/recurringBillingProcess.php';
require_once __DIR__ . '/organizationUnitHelpers.php';

$options = getopt('', ['operator:', 'date::', 'class::']);
$operatorId = (int)($options['operator'] ?? 0);
$runDate = trim((string)($options['date'] ?? date('Y-m-d')));

if ($operatorId <= 0) {
    fwrite(STDERR, "Usage: php process/runRecurringBilling.php --operator=OPERATOR_ID [--class=CLASS_ID] [--date=YYYY-MM-DD]
");
    exit(2);
}
if (!rb_date_is_valid($runDate)) {
    fwrite(STDERR, "Invalid --date value. Use YYYY-MM-DD.
");
    exit(2);
}

try {
    $pdo = getDB();
    $operator = $pdo->prepare('SELECT pkey FROM operator WHERE pkey = ? AND COALESCE(deleted, 0) = 0 LIMIT 1');
    $operator->execute([$operatorId]);
    if (!$operator->fetchColumn()) {
        throw new RuntimeException('The recurring-billing operator does not exist or is inactive.');
    }

    $classId = (int)($options['class'] ?? 0) > 0 ? water_required_class((int)$options['class'], 'the recurring bills') : null;
    $result = WaterPosting::withClass($classId, fn() => rb_process_recurring_bills($pdo, $runDate, $operatorId));
    if (!$result['success']) {
        throw new RuntimeException((string)$result['message']);
    }

    fwrite(STDOUT, sprintf(
        "[%s] Recurring billing completed: %d bill(s) generated; %d cycle(s) advanced.
",
        date('Y-m-d H:i:s'),
        (int)$result['generated'],
        (int)$result['advanced']
    ));
    exit(0);
} catch (Throwable $e) {
    error_log('[runRecurringBilling] ' . $e->getMessage());
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] Recurring billing failed: ' . $e->getMessage() . "
");
    exit(1);
}
