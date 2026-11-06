<?php
/**
 * Direct schedule-paid toggles are disabled. Schedule payment state must be
 * produced by the collection/allocation workflow so receipts and accounting
 * cannot be bypassed.
 */
require_once __DIR__ . '/../database/config.php';
require_once '../database/config.php';
require_once __DIR__ . '/accessControlHelpers.php';
$__processGuardPdo = getDB(); requireProcessAccess($__processGuardPdo); unset($__processGuardPdo);
/* BUSINESS_MUTATION_GUARD_START */
require_once __DIR__ . '/../common/businessRequestGuard.php';
business_require_mutation(getDB(), '../views/installment_bill.php', ['action' => 'billing']);
/* BUSINESS_MUTATION_GUARD_END */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_SESSION['error_msg'] = 'Installment schedules are read-only here. Record or reverse payments through the Collections module so the receipt, allocation, balance, and journal remain synchronized.';
}
header('Location: ../views/installment_bill.php'); exit;
