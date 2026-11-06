<?php
/**
 * Billing-period edit and lifecycle transition handler.
 *
 * Date/code fields become immutable as soon as any reading exists. Closing is
 * explicit. Reopening is allowed only when no billed or paid reading exists.
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

function edit_period_redirect(): void
{
    header('Location: ../views/bill_period.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    edit_period_redirect();
}

$periodId = (int)($_POST['period_id'] ?? 0);
$action = strtolower(trim((string)($_POST['action'] ?? $_POST['status'] ?? 'update')));
$userId = (int)($_SESSION['user_id'] ?? 0);
$now = date('Y-m-d H:i:s');

if ($periodId <= 0 || $userId <= 0 || !in_array($action, ['update', 'open', 'reopen', 'closed', 'close'], true)) {
    $_SESSION['error_msg'] = 'Invalid billing-period request.';
    edit_period_redirect();
}

$pdo = getDB();
try {
    $pdo->beginTransaction();
    $period = bp_fetch_for_update($pdo, $periodId);
    $activity = bp_activity_summary($pdo, $periodId, true);
    $old = $period;

    if (in_array($action, ['closed', 'close'], true)) {
        if ($period['status'] === 'closed') {
            throw new RuntimeException('This billing period is already closed.');
        }
        if ($activity['unbilled_count'] > 0) {
            throw new RuntimeException('This period still has unposted meter readings. Post or remove those drafts before closing the period.');
        }
        $stmt = $pdo->prepare("
            UPDATE bill_periods
            SET status = 'closed', closed_by = ?, closed_at = ?, updated_at = ?
            WHERE period_id = ? AND status = 'open'
        ");
        $stmt->execute([$userId, $now, $now, $periodId]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('The period state changed before it could be closed.');
        }
        $message = 'Billing period closed successfully.';
        $auditAction = 'CLOSE_BILL_PERIOD';
    } elseif (in_array($action, ['open', 'reopen'], true)) {
        if ($period['status'] === 'open') {
            throw new RuntimeException('This billing period is already open.');
        }
        if ($activity['billed_count'] > 0 || $activity['paid_count'] > 0) {
            throw new RuntimeException('A period with billed or paid readings cannot be reopened. Use a controlled billing adjustment instead.');
        }
        $stmt = $pdo->prepare("
            UPDATE bill_periods
            SET status = 'open', closed_by = NULL, closed_at = NULL, updated_at = ?
            WHERE period_id = ? AND status = 'closed'
        ");
        $stmt->execute([$now, $periodId]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('The period state changed before it could be reopened.');
        }
        $message = 'Billing period reopened successfully.';
        $auditAction = 'REOPEN_BILL_PERIOD';
    } else {
        if ($activity['reading_count'] > 0) {
            throw new RuntimeException('Period code and dates are immutable after the first meter reading is recorded.');
        }
        if ($period['status'] !== 'open') {
            throw new RuntimeException('Closed period dates cannot be edited. Reopen an unused period first.');
        }

        $startDate = bp_parse_datetime((string)($_POST['date_from'] ?? ''), '12:00:00');
        $endDate = bp_parse_datetime((string)($_POST['date_to'] ?? ''), '11:59:00');
        if (!$startDate || !$endDate) {
            throw new InvalidArgumentException('Invalid period date/time values.');
        }
        bp_validate_range($startDate, $endDate);
        bp_assert_no_overlap($pdo, $startDate, $endDate, $periodId);
        $code = bp_generate_code($startDate, $endDate);
        $stmt = $pdo->prepare("
            UPDATE bill_periods
            SET bp_code = ?, start_date = ?, end_date = ?, updated_at = ?
            WHERE period_id = ? AND status = 'open'
        ");
        $stmt->execute([$code, $startDate, $endDate, $now, $periodId]);
        $message = 'Billing period updated successfully.';
        $auditAction = 'UPDATE_BILL_PERIOD';
    }

    logAudit($pdo, $userId, $auditAction, 'bill_periods', (string)$periodId, json_encode([
        'before' => [
            'bp_code' => $old['bp_code'],
            'start_date' => $old['start_date'],
            'end_date' => $old['end_date'],
            'status' => $old['status'],
        ],
        'activity' => $activity,
        'requested_action' => $action,
    ], JSON_UNESCAPED_SLASHES));

    $pdo->commit();
    $_SESSION['success_msg'] = $message;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['error_msg'] = $e->getMessage();
}

edit_period_redirect();
