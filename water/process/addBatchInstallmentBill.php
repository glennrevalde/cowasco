<?php
/** Batch fixed-amortization installment creation. Inventory is intentionally excluded. */
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

function redirect_batch_installment(string $message, string $type = 'error'): never
{
    $_SESSION[$type === 'success' ? 'success_msg' : 'error_msg'] = $message;
    header('Location: ../views/installment_bill.php'); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect_batch_installment('Invalid request method.'); }

$pdo = getDB();
try {
    $raw = is_array($_POST['member_ids'] ?? null) ? $_POST['member_ids'] : [];
    $memberIds = array_values(array_unique(array_filter(array_map('intval', $raw), fn(int $id): bool => $id > 0)));
    sort($memberIds, SORT_NUMERIC);
    if (!$memberIds || count($memberIds) > 500) { throw new InvalidArgumentException('Select between 1 and 500 members.'); }
    $billCodeId = (int)($_POST['bill_code_id'] ?? 0);
    $grossAmount = bc_cents_amount(bc_money_cents($_POST['total_amount'] ?? null, 'Total amount'));
    $term = (int)($_POST['term'] ?? 0);
    $paymentMode = trim((string)($_POST['payment_mode'] ?? ''));
    $startDate = bc_date($_POST['start_date'] ?? '', 'Start date');
    $scheduleDates = bc_installment_dates($startDate, $term, $paymentMode);
    $grossSchedules = bc_split_cents($grossAmount, count($scheduleDates));
    $createdBy = (int)($_SESSION['user_id'] ?? 0);
    if ($createdBy <= 0) { throw new RuntimeException('Your session is missing a valid operator identifier.'); }
    // the class of the bill (Chart of Accounts > Class List) replaces the former branch / segment / division
    $classId = water_required_class($_POST['class_id'] ?? null, 'the installment contracts');
    $branchId = 0;
    $segmentId = 0;
    $divisionId = 0;

    $pdo->beginTransaction();
    foreach ($memberIds as $memberId) { bc_lock_active_member($pdo, $memberId); }
    assert_not_water_billing_reserved_bill_code($pdo, $billCodeId, 'Batch Installment Billing');
    $bc = bc_lock_bill_code($pdo, $billCodeId);
    if (bc_is_inventory($bc)) { throw new RuntimeException('Batch installment enrollment does not support Inventory bill codes. Create inventory installments one member at a time so stock is locked per contract.'); }

    $billInsert = $pdo->prepare("INSERT INTO installment_bills
        (global_ref_number, member_id, bill_code_id, quantity, inventory_unit_cost, inventory_total_cost,
         payment_mode, total_amount, gross_amount, discount_amount, discount_id, discount_rule_id, term,
         amortization_type, start_date, status, branch_id, segment_id, division_id, created_at, updated_at)
        VALUES (?, ?, ?, 1.00, 0.00, 0.00, ?, ?, ?, ?, ?, ?, ?, 'fixed', ?, 'active', ?, ?, ?, NOW(), NOW())");
    $scheduleInsert = $pdo->prepare("INSERT INTO installment_schedules (installment_id, due_date, amount, status) VALUES (?, ?, ?, 'pending')");
    $created = 0; $totalDiscount = 0.0;
    foreach ($memberIds as $memberId) {
        $discount = dp_compute_member_bill_discount($pdo, $memberId, $billCodeId, 'installment', $grossAmount);
        $netAmount = bc_cents_amount(bc_money_cents($discount['net_amount'], 'Net installment amount', true));
        $netSchedules = dp_split_net_amounts($grossSchedules, $netAmount);
    bc_assert_schedule_total($netSchedules, $netAmount, 'Net installment schedule');
        $globalRef = generateGlobalRef($pdo);
        $billInsert->execute([$globalRef, $memberId, $billCodeId, $paymentMode, $netAmount, $discount['gross_amount'],
            $discount['discount_amount'], $discount['discount_id'], $discount['discount_rule_id'], $term, $startDate,
            $branchId, $segmentId, $divisionId]);
        $installmentId = (int)$pdo->lastInsertId();
        foreach ($scheduleDates as $index => $dueDate) {
            $scheduleInsert->execute([$installmentId, $dueDate, bc_cents_amount(bc_money_cents($netSchedules[$index], 'Schedule amount', true))]);
        }
        WaterPosting::withClass($classId, fn() => ib_create_installment_journal($pdo, $startDate, $bc, $memberId, $installmentId, $netAmount, 1.00, $branchId, $segmentId, $divisionId));
        logAudit($pdo, $createdBy, 'BATCH_CREATE_INSTALLMENT', 'installment_bills', (string)$installmentId, json_encode([
            'global_ref_number' => $globalRef, 'member_id' => $memberId, 'gross_amount' => $grossAmount,
            'net_amount' => $netAmount, 'schedule_dates' => $scheduleDates, 'discount' => dp_discount_audit_payload($discount),
        ], JSON_THROW_ON_ERROR));
        $totalDiscount += (float)$discount['discount_amount']; $created++;
    }
    $pdo->commit();
    $suffix = $totalDiscount > 0 ? ' Total discount applied: ₱' . number_format($totalDiscount, 2) . '.' : '';
    redirect_batch_installment("Batch installment enrollment completed. {$created} contract(s) created.{$suffix}", 'success');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('Batch Installment Error: ' . $e->getMessage());
    redirect_batch_installment($e->getMessage());
}
