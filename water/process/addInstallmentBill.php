<?php
/** Create one validated installment contract with a server-generated schedule. */
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
require_once __DIR__ . '/organizationUnitHelpers.php';
require_once 'installmentBillingInventoryHelpers.php';
require_once '../common/discountPrivilegeBillingHelpers.php';
require_once '../common/waterBillCodeExclusionHelpers.php';

function redirect_installment_add(string $message, string $type = 'error'): never
{
    $_SESSION[$type === 'success' ? 'success_msg' : 'error_msg'] = $message;
    header('Location: ../views/installment_bill.php'); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect_installment_add('Invalid request method.'); }

$pdo = getDB();
try {
    $memberId = (int)($_POST['member_id'] ?? 0);
    $billCodeId = (int)($_POST['bill_code_id'] ?? 0);
    $submittedAmount = $_POST['total_amount'] ?? null;
    $quantity = bc_positive_quantity($_POST['quantity'] ?? 1);
    $term = (int)($_POST['term'] ?? 0);
    $paymentMode = trim((string)($_POST['payment_mode'] ?? ''));
    $amortizationType = trim((string)($_POST['amortization_type'] ?? ''));
    if (!in_array($amortizationType, ['fixed', 'flexible'], true)) { throw new InvalidArgumentException('Invalid amortization type.'); }
    $startDate = bc_date($_POST['start_date'] ?? '', 'Start date');
    $scheduleDates = bc_installment_dates($startDate, $term, $paymentMode);
    $createdBy = (int)($_SESSION['user_id'] ?? 0);
    if ($createdBy <= 0) { throw new RuntimeException('Your session is missing a valid operator identifier.'); }
    // the class of the bill (Chart of Accounts > Class List) replaces the former branch / segment / division
    $classId = water_required_class($_POST['class_id'] ?? null, 'the installment contract');
    $branchId = 0;
    $segmentId = 0;
    $divisionId = 0;

    $pdo->beginTransaction();
    bc_lock_active_member($pdo, $memberId);
    assert_not_water_billing_reserved_bill_code($pdo, $billCodeId, 'Installment Billing');
    $bc = bc_lock_bill_code($pdo, $billCodeId);
    $isInventory = bc_is_inventory($bc);
    $inventoryQty = $isInventory ? $quantity : 1.00;
    $grossAmount = bc_authoritative_gross($bc, $inventoryQty, $submittedAmount);
    // the unit cost is the average cost of the cooperative item the bill code maps to (one costing)
    $unitCost = $isInventory ? bc_cents_amount(bc_money_cents(ib_item_unit_cost($pdo, $billCodeId), 'Inventory unit cost', true)) : 0.00;
    $totalCost = $isInventory ? round($inventoryQty * $unitCost, 2, PHP_ROUND_HALF_UP) : 0.00;

    if ($isInventory) {
        ib_assert_inventory_item($bc);
        $available = ib_available_stock($pdo, $billCodeId);
        if ($inventoryQty > $available) { throw new RuntimeException('Not enough inventory quantity balance. Available: ' . number_format($available, 2)); }
    }

    $grossSchedules = $amortizationType === 'fixed'
        ? bc_split_cents($grossAmount, count($scheduleDates))
        : bc_validate_flexible_amounts($_POST['schedule_amounts'] ?? null, count($scheduleDates), $grossAmount);

    $discount = dp_compute_member_bill_discount($pdo, $memberId, $billCodeId, 'installment', $grossAmount);
    $netAmount = bc_cents_amount(bc_money_cents($discount['net_amount'], 'Net installment amount', true));
    $netSchedules = dp_split_net_amounts($grossSchedules, $netAmount);
    bc_assert_schedule_total($netSchedules, $netAmount, 'Net installment schedule');
    if (count($netSchedules) !== count($scheduleDates)) { throw new RuntimeException('Unable to produce a complete installment schedule.'); }

    $globalRef = generateGlobalRef($pdo);
    $insert = $pdo->prepare("INSERT INTO installment_bills
        (global_ref_number, member_id, bill_code_id, quantity, inventory_unit_cost, inventory_total_cost,
         payment_mode, total_amount, gross_amount, discount_amount, discount_id, discount_rule_id, term,
         amortization_type, start_date, status, branch_id, segment_id, division_id, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, ?, NOW(), NOW())");
    $insert->execute([$globalRef, $memberId, $billCodeId, $inventoryQty, $unitCost, $totalCost, $paymentMode,
        $netAmount, $discount['gross_amount'], $discount['discount_amount'], $discount['discount_id'],
        $discount['discount_rule_id'], $term, $amortizationType, $startDate, $branchId, $segmentId, $divisionId]);
    $installmentId = (int)$pdo->lastInsertId();

    $scheduleInsert = $pdo->prepare("INSERT INTO installment_schedules (installment_id, due_date, amount, status) VALUES (?, ?, ?, 'pending')");
    foreach ($scheduleDates as $index => $dueDate) {
        $scheduleInsert->execute([$installmentId, $dueDate, bc_cents_amount(bc_money_cents($netSchedules[$index], 'Schedule amount', true))]);
    }

    if ($isInventory) {
        ib_add_stock_movement($pdo, $billCodeId, 'SALE', $installmentId, $startDate, 0, $inventoryQty, $unitCost,
            "Installment inventory deduction #{$installmentId}", $createdBy);
    }
    WaterPosting::withClass($classId, fn() => ib_create_installment_journal($pdo, $startDate, $bc, $memberId, $installmentId, $netAmount, $inventoryQty, $branchId, $segmentId, $divisionId));
    logAudit($pdo, $createdBy, 'CREATE_INSTALLMENT', 'installment_bills', (string)$installmentId, json_encode([
        'global_ref_number' => $globalRef, 'member_id' => $memberId, 'bill_code_id' => $billCodeId,
        'quantity' => $inventoryQty, 'gross_amount' => $grossAmount, 'net_amount' => $netAmount,
        'term' => $term, 'payment_mode' => $paymentMode, 'amortization_type' => $amortizationType,
        'server_generated_dates' => $scheduleDates, 'discount' => dp_discount_audit_payload($discount),
    ], JSON_THROW_ON_ERROR));

    $pdo->commit();
    $suffix = (float)$discount['discount_amount'] > 0 ? ' Discount applied: ₱' . number_format((float)$discount['discount_amount'], 2) . '.' : '';
    redirect_installment_add("Installment Bill created! (Ref #{$globalRef}){$suffix}", 'success');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('Add Installment Error: ' . $e->getMessage());
    redirect_installment_add($e->getMessage());
}
