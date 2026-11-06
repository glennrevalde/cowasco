<?php
/** Create one validated one-time receivable and its dependent records atomically. */
require_once __DIR__ . '/../database/config.php';

require_once '../database/config.php';
require_once __DIR__ . '/accessControlHelpers.php';
$__processGuardPdo = getDB();
requireProcessAccess($__processGuardPdo);
/* BUSINESS_MUTATION_GUARD_START */
require_once __DIR__ . '/../common/businessRequestGuard.php';
business_require_mutation(getDB(), '../views/one_time_billing.php', ['action' => 'billing']);
/* BUSINESS_MUTATION_GUARD_END */
unset($__processGuardPdo);

require_once '../common/billingHelpers.php';
require_once '../common/billCreationHelpers.php';
require_once __DIR__ . '/organizationUnitHelpers.php';
require_once 'oneTimeBillingInventoryHelpers.php';
require_once '../common/discountPrivilegeBillingHelpers.php';
require_once '../common/waterBillCodeExclusionHelpers.php';

function redirect_one_time_add(string $message, string $type = 'error'): never
{
    $_SESSION[$type === 'success' ? 'success_msg' : 'error_msg'] = $message;
    header('Location: ../views/one_time_billing.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_one_time_add('Invalid request method.');
}

$pdo = getDB();
try {
    $memberId = (int)($_POST['member_id'] ?? 0);
    $billDate = bc_date($_POST['bill_date'] ?? '', 'Bill date');
    $noDueDate = isset($_POST['no_due_date']) && (string)$_POST['no_due_date'] === '1';
    $dueDate = $noDueDate ? null : bc_date($_POST['due_date'] ?? '', 'Due date');
    $termDays = $dueDate === null ? 0 : bc_term_days($billDate, $dueDate);
    $createdBy = (int)($_SESSION['user_id'] ?? 0);
    if ($createdBy <= 0) { throw new RuntimeException('Your session is missing a valid operator identifier.'); }

    // the charges ticked on the form, each with its amount (a material: its quantity, priced from the bill code);
    // a form of one charge (bill_code_id, total_amount, quantity) is read the same way
    $charges = [];
    if (isset($_POST['charges']) && is_array($_POST['charges'])) {
        foreach ($_POST['charges'] as $codeId => $c) {
            if (!is_array($c) || (string)($c['on'] ?? '') !== '1') {
                continue;
            }
            $charges[(int)$codeId] = ['amount' => $c['amount'] ?? null, 'quantity' => $c['quantity'] ?? 1];
        }
    } elseif ((int)($_POST['bill_code_id'] ?? 0) > 0) {
        $charges[(int)$_POST['bill_code_id']] = ['amount' => $_POST['total_amount'] ?? null, 'quantity' => $_POST['quantity'] ?? 1];
    }
    unset($charges[0]);
    if (!$charges) {
        throw new RuntimeException('Tick at least one charge and enter its amount.');
    }
    if (count($charges) > 30) {
        throw new RuntimeException('Issue at most 30 charges at a time.');
    }

    // the class of the bill (Chart of Accounts > Class List) replaces the former branch / segment / division
    $classId = water_required_class($_POST['class_id'] ?? null, 'the one-time bill');
    $branchId = 0;
    $segmentId = 0;
    $divisionId = 0;

    $pdo->beginTransaction();
    bc_lock_active_member($pdo, $memberId);
    $refs = [];
    $netTotal = 0.0;
    $discountTotal = 0.0;
    // all the charges or none: each is its own one-time bill (its own reference, receivable and journal entry)
    foreach ($charges as $billCodeId => $charge) {
        assert_not_water_billing_reserved_bill_code($pdo, $billCodeId, 'One-Time Billing');
        $bc = bc_lock_bill_code($pdo, $billCodeId);
        $label = (string)($bc['code'] ?? ('#' . $billCodeId));

        $isInventory = bc_is_inventory($bc);
        try {
            $quantity = $isInventory ? bc_positive_quantity($charge['quantity']) : 1.00;
            $inventoryQty = $isInventory ? $quantity : 1.00;
            $grossAmount = bc_authoritative_gross($bc, $inventoryQty, $charge['amount']);
        } catch (Throwable $e) {
            throw new RuntimeException($label . ': ' . $e->getMessage());
        }
        // the unit cost is the average cost of the cooperative item the bill code maps to (one costing)
        $inventoryUnitCost = $isInventory ? bc_cents_amount(bc_money_cents(otb_item_unit_cost($pdo, $billCodeId), 'Inventory unit cost', true)) : 0.00;
        $inventoryTotalCost = $isInventory ? round($inventoryQty * $inventoryUnitCost, 2, PHP_ROUND_HALF_UP) : 0.00;

        if ($isInventory) {
            otb_assert_inventory_item($bc);
            $availableQty = otb_available_stock($pdo, $billCodeId);
            if ($inventoryQty > $availableQty) {
                throw new RuntimeException($label . ': not enough inventory quantity balance. Available: ' . number_format($availableQty, 2));
            }
        }

        $discount = dp_compute_member_bill_discount($pdo, $memberId, $billCodeId, 'one_time', $grossAmount);
        $netAmount = bc_cents_amount(bc_money_cents($discount['net_amount'], 'Net bill amount', true));
        $globalRef = generateGlobalRef($pdo);

        $stmt = $pdo->prepare("INSERT INTO one_time_bills
            (global_ref_number, member_id, bill_code_id, quantity, inventory_unit_cost, inventory_total_cost,
             bill_date, due_date, term_days, total_amount, gross_amount, discount_amount, discount_id,
             discount_rule_id, amount_paid, status, branch_id, segment_id, division_id, created_by, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0.00, 'unpaid', ?, ?, ?, ?, NOW(), NOW())");
        $stmt->execute([
            $globalRef, $memberId, $billCodeId, $inventoryQty, $inventoryUnitCost, $inventoryTotalCost,
            $billDate, $dueDate, $termDays, $netAmount, $discount['gross_amount'], $discount['discount_amount'],
            $discount['discount_id'], $discount['discount_rule_id'], $branchId, $segmentId, $divisionId, $createdBy,
        ]);
        $billId = (int)$pdo->lastInsertId();

        if ($isInventory) {
            otb_add_stock_movement($pdo, $billCodeId, 'SALE', $billId, $billDate, 0, $inventoryQty, $inventoryUnitCost,
                "One-time bill inventory deduction #{$billId}", $createdBy);
        }

        WaterPosting::withClass($classId, fn() => otb_create_sales_journal($pdo, $globalRef, $billDate, $bc, $memberId, $billId, $netAmount, $inventoryQty, $branchId, $segmentId, $divisionId));
        logAudit($pdo, $createdBy, 'CREATE_ONE_TIME_BILL', 'one_time_bills', (string)$billId, json_encode([
            'global_ref_number' => $globalRef,
            'member_id' => $memberId,
            'bill_code_id' => $billCodeId,
            'bill_date' => $billDate,
            'due_date' => $dueDate,
            'no_due_date' => $noDueDate,
            'quantity' => $inventoryQty,
            'gross_amount' => $grossAmount,
            'net_amount' => $netAmount,
            'charges_in_form' => count($charges),
            'inventory_price_source' => $isInventory ? 'bill_codes.sales_price' : null,
            'inventory_cost_source' => $isInventory ? 'items.avg_cost' : null,
            'discount' => dp_discount_audit_payload($discount),
        ], JSON_THROW_ON_ERROR));
        $refs[] = '#' . $globalRef . ' ' . $label;
        $netTotal += $netAmount;
        $discountTotal += (float)$discount['discount_amount'];
    }

    $pdo->commit();
    $discountMsg = $discountTotal > 0.004 ? ' Discount applied: ₱' . number_format($discountTotal, 2) . '.' : '';
    $msg = count($refs) === 1
        ? "One-time bill (Ref {$refs[0]}) successfully issued!{$discountMsg}"
        : count($refs) . ' one-time bills issued for ₱' . number_format($netTotal, 2) . ': ' . implode(', ', $refs) . '.' . $discountMsg;
    redirect_one_time_add($msg, 'success');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('Add One Time Bill Error: ' . $e->getMessage());
    redirect_one_time_add($e->getMessage());
}
