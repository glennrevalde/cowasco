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
business_require_mutation(getDB(), '../views/installment_bill.php', ['action' => 'billing']);
/* BUSINESS_MUTATION_GUARD_END */

require_once '../common/superAdminGuard.php';
require_once '../common/billLifecycleHelpers.php';

wb_require_super_admin('process');

$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $installment_id = filter_input(INPUT_POST, 'installment_id', FILTER_VALIDATE_INT);
    $user_id = (int)($_SESSION['user_id'] ?? 1);

    if ($installment_id) {
        try {
            $pdo->beginTransaction();
            bl_soft_delete_bill($pdo, 'installment', (int)$installment_id, $user_id, 'Deleted from Installment Billing page');
            $pdo->commit();

            $_SESSION['success_msg'] = "Installment bill marked as deleted. It was not physically removed.";
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $_SESSION['error_msg'] = $e->getMessage();
        }
    }

    header("Location: ../views/installment_bill.php");
    exit();
}

header("Location: ../views/installment_bill.php");
exit();
