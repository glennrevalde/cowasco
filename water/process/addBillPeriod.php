<?php
/**
 * Create one submitted, non-overlapping billing period atomically.
 */

require_once __DIR__ . '/../database/config.php';
require_once '../database/config.php';
/* PROCESS_ACCESS_GUARD_START */
require_once __DIR__ . '/accessControlHelpers.php';
$__processGuardPdo = getDB();
requireProcessAccess($__processGuardPdo);
unset($__processGuardPdo);
/* PROCESS_ACCESS_GUARD_END */
/* BUSINESS_MUTATION_GUARD_START */
require_once __DIR__ . '/../common/businessRequestGuard.php';
business_require_mutation(getDB(), '../views/bill_period.php');
/* BUSINESS_MUTATION_GUARD_END */

require_once '../common/billingHelpers.php';
require_once '../common/billingPeriodHelpers.php';

function redirect_to_bill_period(): void
{
    header('Location: ../views/bill_period.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_to_bill_period();
}

$startDate = bp_parse_datetime((string)($_POST['date_from'] ?? ''), '12:00:00');
$endDate = bp_parse_datetime((string)($_POST['date_to'] ?? ''), '11:59:00');
$duration = (int)($_POST['duration'] ?? 1);
$openedBy = (int)($_SESSION['user_id'] ?? 0);
$now = date('Y-m-d H:i:s');

if (!$startDate || !$endDate) {
    $_SESSION['error_msg'] = 'Please enter valid From and To date/time values.';
    redirect_to_bill_period();
}
if ($endDate <= $startDate) {
    $_SESSION['error_msg'] = 'The end date and time must be later than the start date and time.';
    redirect_to_bill_period();
}
if (!in_array($duration, [1, 3, 6, 12], true)) {
    $_SESSION['error_msg'] = 'Invalid billing-period duration.';
    redirect_to_bill_period();
}
if ($openedBy <= 0) {
    $_SESSION['error_msg'] = 'Your session is missing a valid operator identifier.';
    redirect_to_bill_period();
}
// Duration supplies the default end date, but the operator may intentionally
// override it. The server still validates the submitted range and overlap.
$defaultEndDate = bp_period_end_from_start($startDate, $duration);
$isCustomEndDate = $endDate !== $defaultEndDate;
$startObject = new DateTimeImmutable($startDate);
$endObject = new DateTimeImmutable($endDate);
$spanSeconds = $endObject->getTimestamp() - $startObject->getTimestamp();
$calendarSpanDays = (int)floor($spanSeconds / 86400);
$spanHours = (int)floor(($spanSeconds % 86400) / 3600);
$spanMinutes = (int)floor(($spanSeconds % 3600) / 60);

$pdo = getDB();
try {
    bp_validate_range($startDate, $endDate);
    $pdo->beginTransaction();

    $currentId = bp_insert($pdo, $startDate, $endDate, $openedBy, $now);
    $currentCode = bp_generate_code($startDate, $endDate);
    logAudit($pdo, $openedBy, 'CREATE_BILL_PERIOD', 'bill_periods', (string)$currentId, json_encode([
        'bp_code' => $currentCode,
        'start_date' => $startDate,
        'end_date' => $endDate,
        'duration_months' => $duration,
        'default_end_date' => $defaultEndDate,
        'custom_end_date' => $isCustomEndDate,
        'measured_span' => [
            'days' => $calendarSpanDays,
            'hours' => $spanHours,
            'minutes' => $spanMinutes,
        ],
        'cutoff_start_time' => $startObject->format('h:i A'),
        'cutoff_end_time' => $endObject->format('h:i A'),
    ], JSON_UNESCAPED_SLASHES));

    $message = "Billing period {$currentCode} created successfully.";
    if ($isCustomEndDate) {
        $message .= " The manually selected end date was used.";
    }

    $pdo->commit();
    $_SESSION['success_msg'] = $message;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['error_msg'] = $e->getMessage();
}

redirect_to_bill_period();
