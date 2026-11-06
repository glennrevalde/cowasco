<?php
/** Delete an unused billing period. Historical periods are never deleted. */

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

function delete_period_redirect(): void
{
    header('Location: ../views/bill_period.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error_msg'] = 'Billing periods can only be deleted through a confirmed form submission.';
    delete_period_redirect();
}

$periodId = (int)($_POST['period_id'] ?? 0);
$userId = (int)($_SESSION['user_id'] ?? 0);
if ($periodId <= 0 || $userId <= 0) {
    $_SESSION['error_msg'] = 'Invalid billing period.';
    delete_period_redirect();
}

$pdo = getDB();
try {
    $pdo->beginTransaction();
    $period = bp_fetch_for_update($pdo, $periodId);
    $activity = bp_activity_summary($pdo, $periodId, true);
    if ($activity['reading_count'] > 0) {
        throw new RuntimeException('This period contains meter readings and is historical. It cannot be deleted.');
    }

    $stmt = $pdo->prepare('DELETE FROM bill_periods WHERE period_id = ?');
    $stmt->execute([$periodId]);
    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('The billing period could not be deleted.');
    }

    logAudit($pdo, $userId, 'DELETE_UNUSED_BILL_PERIOD', 'bill_periods', (string)$periodId, json_encode([
        'bp_code' => $period['bp_code'],
        'start_date' => $period['start_date'],
        'end_date' => $period['end_date'],
        'status' => $period['status'],
    ], JSON_UNESCAPED_SLASHES));
    $pdo->commit();
    $_SESSION['success_msg'] = 'Unused billing period deleted successfully.';
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['error_msg'] = $e->getMessage();
}

delete_period_redirect();
