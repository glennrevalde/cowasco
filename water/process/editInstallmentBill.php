<?php
/** Controlled installment contract cancellation/reactivation. Completion is schedule-derived. */
require_once __DIR__ . '/../database/config.php';
require_once '../database/config.php';
require_once __DIR__ . '/accessControlHelpers.php';
$__processGuardPdo = getDB(); requireProcessAccess($__processGuardPdo); unset($__processGuardPdo);
/* BUSINESS_MUTATION_GUARD_START */
require_once __DIR__ . '/../common/businessRequestGuard.php';
business_require_mutation(getDB(), '../views/installment_bill.php', ['action' => 'billing']);
/* BUSINESS_MUTATION_GUARD_END */
require_once '../common/billingHelpers.php';
require_once '../common/billCreationHelpers.php';
require_once 'installmentBillingInventoryHelpers.php';
require_once __DIR__ . '/organizationUnitHelpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../views/installment_bill.php'); exit;
}

$pdo = getDB();
try {
    $installmentId = (int)($_POST['installment_id'] ?? 0);
    $requested = trim((string)($_POST['status'] ?? ''));
    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($installmentId <= 0 || $userId <= 0) { throw new InvalidArgumentException('Invalid installment or operator session.'); }
    if (!in_array($requested, ['active', 'cancelled'], true)) {
        throw new InvalidArgumentException('Installment completion is calculated from the schedules and cannot be selected manually.');
    }
    // the class of the change (Chart of Accounts > Class List): the adjusting journal entry carries it
    $classId = water_required_class($_POST['class_id'] ?? null, 'the installment change');

    $pdo->beginTransaction();
    $stmt = $pdo->prepare("SELECT i.*, bc.code, bc.type, bc.ar_account, bc.income_account, bc.cogs_account, bc.asset_account
        FROM installment_bills i JOIN bill_codes bc ON bc.code_id = i.bill_code_id
        WHERE i.installment_id = ? FOR UPDATE");
    $stmt->execute([$installmentId]);
    $bill = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$bill) { throw new RuntimeException('Installment bill not found.'); }
    if (($bill['bill_lifecycle_status'] ?? 'active') === 'deleted' || ($bill['status'] ?? '') === 'deleted') {
        throw new RuntimeException('A deleted installment cannot be changed.');
    }

    $scheduleState = bc_lock_installment_schedule_state($pdo, $installmentId);
    $paidCount = (int)$scheduleState['paid_count'];
    $pendingCount = (int)$scheduleState['pending_count'];
    if ($paidCount + $pendingCount === 0) { throw new RuntimeException('Installment has no schedules and cannot change status.'); }

    $oldStatus = (string)$bill['status'];
    if ($requested === 'cancelled' && $paidCount > 0) {
        throw new RuntimeException('An installment with paid schedules cannot be cancelled. Reverse its payments through the collection workflow first.');
    }

    $newStatus = $requested === 'active'
        ? bc_installment_status_from_counts($pendingCount, $paidCount, 'active')
        : 'cancelled';
    if ($newStatus === $oldStatus) {
        $pdo->commit();
        $_SESSION['success_msg'] = 'Installment status is already up to date.';
        header('Location: ../views/installment_bill.php'); exit;
    }

    $isInventory = bc_is_inventory($bill);
    $qty = bc_positive_quantity($bill['quantity'] ?? 1);
    $unitCost = $isInventory ? bc_cents_amount(bc_money_cents($bill['inventory_unit_cost'] ?? 0, 'Stored inventory unit cost', true)) : 0.00;
    $totalCost = $isInventory ? round((float)($bill['inventory_total_cost'] ?? ($qty * $unitCost)), 2, PHP_ROUND_HALF_UP) : 0.00;
    $amountDelta = 0.0; $costDelta = 0.0;

    if ($oldStatus !== 'cancelled' && $newStatus === 'cancelled') {
        $amountDelta = -round((float)$bill['total_amount'], 2);
        $costDelta = $isInventory ? -$totalCost : 0.0;
        if ($isInventory) {
            ib_add_stock_movement($pdo, (int)$bill['bill_code_id'], 'ADJUSTMENT', $installmentId, date('Y-m-d'),
                $qty, 0, $unitCost, "Installment #{$installmentId} cancelled; inventory restored", $userId);
        }
    } elseif ($oldStatus === 'cancelled' && $newStatus !== 'cancelled') {
        $amountDelta = round((float)$bill['total_amount'], 2);
        $costDelta = $isInventory ? $totalCost : 0.0;
        if ($isInventory) {
            $available = ib_available_stock($pdo, (int)$bill['bill_code_id']);
            if ($qty > $available) { throw new RuntimeException('Not enough stock to reactivate this installment. Available: ' . number_format($available, 2)); }
            ib_add_stock_movement($pdo, (int)$bill['bill_code_id'], 'ADJUSTMENT', $installmentId, date('Y-m-d'),
                0, $qty, $unitCost, "Installment #{$installmentId} reactivated; inventory deducted again", $userId);
        }
    }

    WaterPosting::withClass($classId, fn() => ib_create_status_adjustment_journal($pdo, date('Y-m-d'), $bill, (int)$bill['member_id'], $installmentId,
        $amountDelta, $costDelta, 'Controlled Status Change'));
    $update = $pdo->prepare('UPDATE installment_bills SET status = ?, updated_at = NOW() WHERE installment_id = ?');
    $update->execute([$newStatus, $installmentId]);
    logAudit($pdo, $userId, 'EDIT_INSTALLMENT_STATUS', 'installment_bills', (string)$installmentId, json_encode([
        'old_status' => $oldStatus, 'new_status' => $newStatus, 'paid_schedules' => $paidCount,
        'pending_schedules' => $pendingCount, 'quantity' => $qty, 'stored_unit_cost' => $unitCost,
        'class_id' => $classId, 'class' => water_class_name($classId),
    ], JSON_THROW_ON_ERROR));

    $pdo->commit();
    $_SESSION['success_msg'] = 'Installment status updated successfully with matching inventory and accounting adjustments.';
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('Edit Installment Error: ' . $e->getMessage());
    $_SESSION['error_msg'] = $e->getMessage();
}
header('Location: ../views/installment_bill.php'); exit;
