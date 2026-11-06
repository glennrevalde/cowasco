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

require_once 'oneTimeBillingInventoryHelpers.php';
require_once __DIR__ . '/organizationUnitHelpers.php';
require_once '../common/discountPrivilegeBillingHelpers.php';
require_once '../common/billCreationHelpers.php';
require_once '../common/billAccountingCorrectionHelpers.php';
require_once '../common/waterBillCodeExclusionHelpers.php';
require_once '../common/billingHelpers.php';

$pdo = getDB();

/**
 * Issues an unpaid one-time bill again with another charge category or date issued: its invoice is voided (the
 * stock of a material comes back), the bill takes the new bill code, date, amounts and quantity, and the corrected
 * invoice "<no>-Rn" is posted on the new date. Refused once anything is collected on the bill. Returns the message.
 */
function otb_reissue_bill(PDO $pdo, array $old, int $newCodeId, string $newBillDate, ?string $dueRaw, float $amount, float $quantity, string $status, ?int $classId, int $userId): string
{
    $billId = (int)$old['bill_id'];
    // issuing a bill again voids and posts invoices: it takes the Edit right on One-Time Billing (Create is not enough)
    $pageKey = access_resolve_key('one_time_billing');
    if ($pageKey !== null && $pageKey !== '' && !Access::can($pageKey, 'edit')) {
        throw new Exception('Changing the charge category or the date issued of a bill needs the Edit right on One-Time Billing.');
    }
    if ($old['status'] !== 'unpaid' || $status !== 'unpaid') {
        throw new Exception('The charge category and the date issued change on an unpaid bill only. Change them first, then cancel the bill if needed.');
    }
    if (round((float)($old['amount_paid'] ?? 0), 2) > 0) {
        throw new Exception('Payments are recorded on this bill: its charge category and date issued can no longer change. Reverse the payment through Collections first.');
    }
    // what was collected on it (the bill's own credit memos, an amount lowered earlier, are not collections)
    if (WaterPosting::billCollected('one_time', $billId) > 0.004) {
        throw new Exception('Collections are applied to the invoice of this bill: its charge category and date issued can no longer change.');
    }
    assert_not_water_billing_reserved_bill_code($pdo, $newCodeId, 'One-Time Billing');
    $newBc = bc_lock_bill_code($pdo, $newCodeId);
    $isInventory = bc_is_inventory($newBc);
    $qty = $isInventory ? bc_positive_quantity($quantity > 0 ? $quantity : 1) : 1.00;
    $gross = bc_authoritative_gross($newBc, $qty, $amount);
    $dueDate = $dueRaw === null ? null : bc_date($dueRaw, 'Due date');
    $termDays = $dueDate === null ? 0 : bc_term_days($newBillDate, $dueDate);
    $disconnectionDays = max(0, (int)($old['disconnection_days'] ?? 0));
    $disconnectionDate = $dueDate !== null && $disconnectionDays > 0
        ? (new DateTimeImmutable($dueDate))->modify('+' . $disconnectionDays . ' days')->format('Y-m-d') : null;
    $oldCode = (string)$old['code'];
    $changes = [];
    if ($newCodeId !== (int)$old['bill_code_id']) {
        $changes[] = 'charge category ' . $oldCode . ' to ' . $newBc['code'];
    }
    if ($newBillDate !== substr((string)$old['bill_date'], 0, 10)) {
        $changes[] = 'date issued ' . substr((string)$old['bill_date'], 0, 10) . ' to ' . $newBillDate;
    }
    $reason = 'One-time bill #' . $billId . ' issued again: ' . implode(', ', $changes);

    // the invoice of the bill is voided: its stock (a material) comes back before the new one is checked
    WaterPosting::voidBill('one_time', $billId, $reason, 'replaced');
    if ($isInventory) {
        otb_assert_inventory_item($newBc);
        $available = otb_available_stock($pdo, $newCodeId);
        if ($qty > $available) {
            throw new Exception($newBc['code'] . ': not enough inventory quantity balance. Available: ' . number_format($available, 2));
        }
    }
    $unitCost = $isInventory ? bc_cents_amount(bc_money_cents(otb_item_unit_cost($pdo, $newCodeId), 'Inventory unit cost', true)) : 0.00;
    $discount = dp_compute_member_bill_discount($pdo, (int)$old['member_id'], $newCodeId, 'one_time', $gross);
    $net = bc_cents_amount(bc_money_cents($discount['net_amount'], 'Net bill amount', true));
    if ($net < 0) {
        throw new Exception('The amount of the bill cannot be below zero.');
    }

    $pdo->prepare("UPDATE one_time_bills SET bill_code_id = :code, bill_date = :bdate, due_date = :due, term_days = :term, disconnection_date = :dd,
                          total_amount = :total, gross_amount = :gross, discount_amount = :disc, discount_id = :did, discount_rule_id = :drid,
                          quantity = :qty, inventory_unit_cost = :uc, inventory_total_cost = :tc,
                          bill_edit_count = COALESCE(bill_edit_count, 0) + 1, last_bill_edited_at = NOW(), last_bill_edited_by = :by, updated_at = NOW()
                    WHERE bill_id = :id")
        ->execute([':code' => $newCodeId, ':bdate' => $newBillDate, ':due' => $dueDate, ':term' => $termDays, ':dd' => $disconnectionDate,
                   ':total' => $net, ':gross' => $discount['gross_amount'], ':disc' => $discount['discount_amount'], ':did' => $discount['discount_id'],
                   ':drid' => $discount['discount_rule_id'], ':qty' => $qty, ':uc' => $unitCost, ':tc' => $isInventory ? round($qty * $unitCost, 2) : 0.00,
                   ':by' => $userId, ':id' => $billId]);
    $row = $old;
    $row['bill_code_id'] = $newCodeId;
    $row['quantity'] = $qty;
    $row['due_date'] = $dueDate;
    $voucher = WaterPosting::withClass($classId, fn() => bl_ac_create_bill_replacement_journal($pdo, 'one_time', $billId, $row, $net, 0.0,
        WaterPosting::eventKey('one_time', $billId), $reason, $userId, $newBillDate));
    bl_ac_store_adjustment_reference($pdo, 'one_time_bills', 'bill_id', $billId, $voucher);
    logAudit($pdo, $userId, 'REISSUE_ONE_TIME_BILL', 'one_time_bills', (string)$billId, json_encode([
        'changes' => $changes, 'old_bill_code_id' => (int)$old['bill_code_id'], 'new_bill_code_id' => $newCodeId,
        'old_bill_date' => $old['bill_date'], 'new_bill_date' => $newBillDate, 'old_amount' => (float)$old['total_amount'], 'new_amount' => $net,
        'quantity' => $qty, 'invoice' => $voucher['jv_entry_id'] ?? null,
    ], JSON_THROW_ON_ERROR));
    return 'Bill issued again (' . implode('; ', $changes) . '): ' . ($voucher
        ? 'invoice ' . $voucher['jv_entry_id'] . ' for ₱' . number_format($net, 2) . ' replaces the first one.'
        : 'nothing is left to collect after the member discount, so the first invoice is voided and none replaces it.');
}

function redirect_one_time_edit($message, $type = 'error')
{
    $_SESSION[$type === 'success' ? 'success_msg' : 'error_msg'] = $message;
    header("Location: ../views/one_time_billing.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../views/one_time_billing.php");
    exit();
}

$bill_id      = filter_input(INPUT_POST, 'bill_id', FILTER_VALIDATE_INT);
$total_amount = filter_input(INPUT_POST, 'total_amount', FILTER_VALIDATE_FLOAT);
$due_date_raw = trim(strip_tags(filter_input(INPUT_POST, 'due_date', FILTER_DEFAULT) ?? ''));
$no_due_date  = isset($_POST['no_due_date']) && (string)$_POST['no_due_date'] === '1';
$status       = trim(strip_tags(filter_input(INPUT_POST, 'status', FILTER_DEFAULT) ?? ''));
$quantity     = filter_input(INPUT_POST, 'quantity', FILTER_VALIDATE_FLOAT);
$created_by   = (int)($_SESSION['user_id'] ?? 1);

if (!$bill_id || $total_amount === false || $total_amount === null || !in_array($status, ['unpaid', 'paid', 'cancelled'], true)) {
    redirect_one_time_edit("Invalid input data.");
}

if ($total_amount < 0) {
    redirect_one_time_edit("The amount cannot be negative.");
}

if ($quantity === false || $quantity === null || $quantity <= 0) {
    $quantity = 1.00;
}

try {
    // the class of the change (Chart of Accounts > Class List): the adjusting journal entry carries it
    $classId = water_required_class($_POST['class_id'] ?? null, 'the bill change');
    $pdo->beginTransaction();

    $billStmt = $pdo->prepare("
        SELECT
            b.*,
            bc.code,
            bc.type,
            bc.ar_account,
            bc.income_account,
            bc.cogs_account,
            bc.asset_account,
            bc.item_id,
            bc.cost_price
        FROM one_time_bills b
        JOIN bill_codes bc ON bc.code_id = b.bill_code_id
        WHERE b.bill_id = :bill_id
        FOR UPDATE
    ");
    $billStmt->execute([':bill_id' => $bill_id]);
    $old = $billStmt->fetch(PDO::FETCH_ASSOC);

    if (!$old) {
        throw new Exception("Bill not found.");
    }

    // a change of the charge category or of the date issued: the bill is issued again (its invoice voided, the
    // corrected one posted on the new date with the new bill code), while nothing is collected on it
    $newCodeId = (int)($_POST['bill_code_id'] ?? 0) ?: (int)$old['bill_code_id'];
    $postedDate = trim((string)($_POST['bill_date'] ?? ''));
    $newBillDate = $postedDate !== '' ? bc_date($postedDate, 'Date issued') : bc_date((string)$old['bill_date'], 'Bill date');
    if ($newCodeId !== (int)$old['bill_code_id'] || $newBillDate !== substr((string)$old['bill_date'], 0, 10)) {
        $msg = otb_reissue_bill($pdo, $old, $newCodeId, $newBillDate, $no_due_date ? null : $due_date_raw, (float)$total_amount, (float)$quantity, $status, $classId, $created_by);
        $pdo->commit();
        $_SESSION['success_msg'] = $msg;
        header("Location: ../views/one_time_billing.php");
        exit();
    }

    $bill_date = bc_date((string)$old['bill_date'], 'Bill date');
    $due_date = $no_due_date ? null : bc_date($due_date_raw, 'Due date');
    $term_days = $due_date === null ? 0 : bc_term_days($bill_date, $due_date);
    $disconnection_days = max(0, (int)($old['disconnection_days'] ?? 0));
    $disconnection_date = null;
    if ($due_date !== null && $disconnection_days > 0) {
        $disconnection_date = (new DateTimeImmutable($due_date))
            ->modify('+' . $disconnection_days . ' days')
            ->format('Y-m-d');
    }

    $isInventory = (($old['type'] ?? '') === 'Inventory');
    $oldStatusActive = $old['status'] !== 'cancelled';
    $newStatusActive = $status !== 'cancelled';

    $oldQty = $isInventory && $oldStatusActive ? (float)($old['quantity'] ?? 1) : 0.00;
    $newQty = $isInventory && $newStatusActive ? round((float)$quantity, 2) : 0.00;
    $qtyDelta = round($newQty - $oldQty, 2);

    // the unit cost follows the cooperative item (average cost); the stored cost is kept for an unchanged bill
    $unitCost = $isInventory ? (abs($qtyDelta) >= 0.005 || (float)($old['inventory_unit_cost'] ?? 0) <= 0 ? (float)otb_item_unit_cost($pdo, (int)$old['bill_code_id']) : (float)$old['inventory_unit_cost']) : 0.00;
    $newInventoryTotalCost = $isInventory ? round($newQty * $unitCost, 2) : 0.00;

    if ($isInventory) {
        otb_assert_inventory_item($old);

        if ($qtyDelta > 0) {
            $availableQty = otb_available_stock($pdo, $old['bill_code_id']);
            if ($qtyDelta > $availableQty) {
                throw new Exception("Not enough inventory quantity balance for the increase. Available: " . number_format($availableQty, 2));
            }

            otb_add_stock_movement(
                $pdo,
                $old['bill_code_id'],
                'ADJUSTMENT',
                $bill_id,
                date('Y-m-d'),
                0,
                $qtyDelta,
                $unitCost,
                "One-time bill #{$bill_id} quantity increase",
                $created_by
            );
        } elseif ($qtyDelta < 0) {
            otb_add_stock_movement(
                $pdo,
                $old['bill_code_id'],
                'ADJUSTMENT',
                $bill_id,
                date('Y-m-d'),
                abs($qtyDelta),
                0,
                $unitCost,
                "One-time bill #{$bill_id} quantity decrease/cancel restoration",
                $created_by
            );
        }
    }

    $discount = dp_compute_member_bill_discount($pdo, (int)$old['member_id'], (int)$old['bill_code_id'], 'one_time', (float)$total_amount);
    $newNetAmount = (float)$discount['net_amount'];

    $oldAccountingAmount = $oldStatusActive ? (float)$old['total_amount'] : 0.00;
    $newAccountingAmount = $newStatusActive ? $newNetAmount : 0.00;
    $amountDelta = round($newAccountingAmount - $oldAccountingAmount, 2);

    $oldAccountingCost = $isInventory && $oldStatusActive ? (float)($old['inventory_total_cost'] ?? ((float)($old['quantity'] ?? 1) * $unitCost)) : 0.00;
    $newAccountingCost = $isInventory && $newStatusActive ? $newInventoryTotalCost : 0.00;
    $costDelta = round($newAccountingCost - $oldAccountingCost, 2);

    WaterPosting::withClass($classId, fn() => otb_create_adjustment_journal(
        $pdo,
        date('Y-m-d'),
        $old,
        $old['member_id'],
        $bill_id,
        $amountDelta,
        $costDelta,
        'Edit',
        $isInventory && $newStatusActive ? $newQty : null
    ));

    $stmt = $pdo->prepare("
        UPDATE one_time_bills
        SET
            total_amount = :total_amount,
            gross_amount = :gross_amount,
            discount_amount = :discount_amount,
            discount_id = :discount_id,
            discount_rule_id = :discount_rule_id,
            quantity = :quantity,
            inventory_unit_cost = :inventory_unit_cost,
            inventory_total_cost = :inventory_total_cost,
            due_date = :due_date,
            term_days = :term_days,
            disconnection_date = :disconnection_date,
            status = :status,
            updated_at = NOW()
        WHERE bill_id = :bill_id
    ");

    $stmt->execute([
        ':total_amount' => $newNetAmount,
        ':gross_amount' => $discount['gross_amount'],
        ':discount_amount' => $discount['discount_amount'],
        ':discount_id' => $discount['discount_id'],
        ':discount_rule_id' => $discount['discount_rule_id'],
        ':quantity' => $isInventory ? $newQty : 1.00,
        ':inventory_unit_cost' => $isInventory ? $unitCost : 0.00,
        ':inventory_total_cost' => $newInventoryTotalCost,
        ':due_date' => $due_date,
        ':term_days' => $term_days,
        ':disconnection_date' => $disconnection_date,
        ':status' => $status,
        ':bill_id' => $bill_id,
    ]);

    $pdo->commit();
    $_SESSION['success_msg'] = "Bill successfully updated. Inventory and accounting adjustments were applied.";

} catch (Exception $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    $_SESSION['error_msg'] = $e->getMessage();
    error_log("Edit One Time Bill Error: " . $e->getMessage());
}

header("Location: ../views/one_time_billing.php");
exit();
