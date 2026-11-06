<?php
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
business_require_mutation(getDB(), '../views/one_time_billing.php', ['action' => 'billing']);
/* BUSINESS_MUTATION_GUARD_END */

require_once '../common/superAdminGuard.php';
require_once '../common/billLifecycleHelpers.php';

wb_require_super_admin('process');

$pdo = getDB();

function redirect_one_time_delete($message, $type = 'error')
{
    $_SESSION[$type === 'success' ? 'success_msg' : 'error_msg'] = $message;
    header("Location: ../views/one_time_billing.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../views/one_time_billing.php");
    exit();
}

$bill_id = filter_input(INPUT_POST, 'bill_id', FILTER_VALIDATE_INT);
$user_id = (int)($_SESSION['user_id'] ?? 1);

if (!$bill_id) {
    redirect_one_time_delete("Invalid bill selected.");
}

try {
    $pdo->beginTransaction();
    bl_soft_delete_bill($pdo, 'one_time', (int)$bill_id, $user_id, 'Deleted from One-Time Billing page');
    $pdo->commit();

    redirect_one_time_delete("Bill marked as deleted. It was not physically removed.", 'success');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    redirect_one_time_delete($e->getMessage());
}
