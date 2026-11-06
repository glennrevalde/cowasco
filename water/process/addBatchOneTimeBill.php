<?php
/** Create a batch of one-time receivables atomically with locked members and stock. */
require_once __DIR__ . '/../database/config.php';
require_once '../database/config.php';
require_once __DIR__ . '/accessControlHelpers.php';
$__processGuardPdo = getDB(); requireProcessAccess($__processGuardPdo); unset($__processGuardPdo);
/* BUSINESS_MUTATION_GUARD_START */
require_once __DIR__ . '/../common/businessRequestGuard.php';
business_require_mutation(getDB(), '../views/one_time_billing.php', ['action' => 'billing']);
/* BUSINESS_MUTATION_GUARD_END */
require_once '../common/billingHelpers.php';
require_once '../common/billCreationHelpers.php';
require_once __DIR__ . '/organizationUnitHelpers.php';
require_once 'oneTimeBillingInventoryHelpers.php';
require_once '../common/discountPrivilegeBillingHelpers.php';
require_once '../common/waterBillCodeExclusionHelpers.php';

function redirect_batch_add(string $message, string $type = 'error'): never
{
    $_SESSION[$type === 'success' ? 'success_msg' : 'error_msg'] = $message;
    header('Location: ../views/one_time_billing.php'); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect_batch_add('Invalid request method.'); }

$pdo = getDB();
try {
    $rawMembers = is_array($_POST['member_ids'] ?? null) ? $_POST['member_ids'] : [];
    $memberIds = array_values(array_unique(array_filter(array_map('intval', $rawMembers), fn(int $id): bool => $id > 0)));
    sort($memberIds, SORT_NUMERIC);
    if (!$memberIds || count($memberIds) > 500) { throw new InvalidArgumentException('Select between 1 and 500 members.'); }

    $billCodeId = (int)($_POST['bill_code_id'] ?? 0);
    $submittedAmount = $_POST['base_amount'] ?? null;
    $quantity = bc_positive_quantity($_POST['quantity'] ?? 1);
    $billDate = bc_date($_POST['bill_date'] ?? '', 'Bill date');
    $noDueDate = isset($_POST['no_due_date']) && (string)$_POST['no_due_date'] === '1';
    $dueDate = $noDueDate ? null : bc_date($_POST['due_date'] ?? '', 'Due date');
    $termDays = $dueDate === null ? 0 : bc_term_days($billDate, $dueDate);
    $createdBy = (int)($_SESSION['user_id'] ?? 0);
    if ($createdBy <= 0) { throw new RuntimeException('Your session is missing a valid operator identifier.'); }
    // the class of the bill (Chart of Accounts > Class List) replaces the former branch / segment / division
    $classId = water_required_class($_POST['class_id'] ?? null, 'the one-time bills');
    $branchId = 0;
    $segmentId = 0;
    $divisionId = 0;

    $pdo->beginTransaction();
    foreach ($memberIds as $memberId) { bc_lock_active_member($pdo, $memberId); }
    assert_not_water_billing_reserved_bill_code($pdo, $billCodeId, 'Batch One-Time Billing');
    $bc = bc_lock_bill_code($pdo, $billCodeId);
    $isInventory = bc_is_inventory($bc);
    $qtyPerMember = $isInventory ? $quantity : 1.00;
    $grossAmount = bc_authoritative_gross($bc, $qtyPerMember, $submittedAmount);
    // the unit cost is the average cost of the cooperative item the bill code maps to (one costing)
    $unitCost = $isInventory ? bc_cents_amount(bc_money_cents(otb_item_unit_cost($pdo, $billCodeId), 'Inventory unit cost', true)) : 0.00;
    $totalCost = $isInventory ? round($qtyPerMember * $unitCost, 2, PHP_ROUND_HALF_UP) : 0.00;

    if ($isInventory) {
        otb_assert_inventory_item($bc);
        $required = round($qtyPerMember * count($memberIds), 2, PHP_ROUND_HALF_UP);
        $available = otb_available_stock($pdo, $billCodeId);
        if ($required > $available) { throw new RuntimeException('Not enough inventory for batch billing. Required: ' . number_format($required, 2) . ', available: ' . number_format($available, 2)); }
    }

    $insert = $pdo->prepare("INSERT INTO one_time_bills
        (global_ref_number, member_id, bill_code_id, quantity, inventory_unit_cost, inventory_total_cost,
         bill_date, due_date, term_days, total_amount, gross_amount, discount_amount, discount_id,
         discount_rule_id, amount_paid, status, branch_id, segment_id, division_id, created_by, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0.00, 'unpaid', ?, ?, ?, ?, NOW(), NOW())");

    $created = 0; $totalDiscount = 0.0;
    foreach ($memberIds as $memberId) {
        $discount = dp_compute_member_bill_discount($pdo, $memberId, $billCodeId, 'one_time', $grossAmount);
        $netAmount = bc_cents_amount(bc_money_cents($discount['net_amount'], 'Net bill amount', true));
        $globalRef = generateGlobalRef($pdo);
        $insert->execute([$globalRef, $memberId, $billCodeId, $qtyPerMember, $unitCost, $totalCost, $billDate, $dueDate,
            $termDays, $netAmount, $discount['gross_amount'], $discount['discount_amount'], $discount['discount_id'],
            $discount['discount_rule_id'], $branchId, $segmentId, $divisionId, $createdBy]);
        $billId = (int)$pdo->lastInsertId();
        if ($isInventory) {
            otb_add_stock_movement($pdo, $billCodeId, 'SALE', $billId, $billDate, 0, $qtyPerMember, $unitCost,
                "Batch one-time bill inventory deduction #{$billId}", $createdBy);
        }
        WaterPosting::withClass($classId, fn() => otb_create_sales_journal($pdo, $globalRef, $billDate, $bc, $memberId, $billId, $netAmount, $qtyPerMember, $branchId, $segmentId, $divisionId));
        logAudit($pdo, $createdBy, 'CREATE_ONE_TIME_BILL_BATCH', 'one_time_bills', (string)$billId, json_encode([
            'global_ref_number' => $globalRef, 'member_id' => $memberId, 'bill_code_id' => $billCodeId,
            'bill_date' => $billDate, 'due_date' => $dueDate, 'no_due_date' => $noDueDate,
            'quantity' => $qtyPerMember, 'gross_amount' => $grossAmount, 'net_amount' => $netAmount,
            'discount' => dp_discount_audit_payload($discount),
        ], JSON_THROW_ON_ERROR));
        $totalDiscount += (float)$discount['discount_amount']; $created++;
    }
    $pdo->commit();
    $suffix = $totalDiscount > 0 ? ' Total discount applied: ₱' . number_format($totalDiscount, 2) . '.' : '';
    redirect_batch_add("Successfully issued {$created} batch bill(s)!{$suffix}", 'success');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('Add Batch One Time Bill Error: ' . $e->getMessage());
    redirect_batch_add($e->getMessage());
}
