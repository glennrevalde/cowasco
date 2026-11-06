<?php
/**
 * process/editBatchOneTimeBill.php
 * Batch updates bill status/dates and applies inventory/accounting adjustments when status changes.
 */

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

$pdo = getDB();

function redirect_batch_edit($message, $type = 'error')
{
    $_SESSION[$type === 'success' ? 'success_msg' : 'error_msg'] = $message;
    header("Location: ../views/one_time_billing.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../views/one_time_billing.php");
    exit();
}

$bill_ids_raw = $_POST['bill_ids'] ?? [];
$bill_ids = array_values(array_filter(array_map('intval', is_array($bill_ids_raw) ? $bill_ids_raw : []), fn($id) => $id > 0));

if (empty($bill_ids)) {
    redirect_batch_edit("No valid bills selected for batch update.");
}

$status = trim(filter_input(INPUT_POST, 'status', FILTER_DEFAULT) ?? '');
$allowed_statuses = ['unpaid', 'paid', 'cancelled'];

if (!in_array($status, $allowed_statuses, true)) {
    redirect_batch_edit("Invalid status value. Must be one of: unpaid, paid, cancelled.");
}

$due_date_raw = trim(strip_tags(filter_input(INPUT_POST, 'due_date', FILTER_DEFAULT) ?? ''));
$clear_due_date = isset($_POST['clear_due_date']) && (string)$_POST['clear_due_date'] === '1';

$update_dates = false;
$due_date = null;

if ($clear_due_date) {
    $update_dates = true;
} elseif ($due_date_raw !== '') {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $due_date_raw);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$d || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $d->format('Y-m-d') !== $due_date_raw) {
        redirect_batch_edit("Invalid due date format. Please use YYYY-MM-DD.");
    }

    $due_date = $due_date_raw;
    $update_dates = true;
}

$created_by = (int)($_SESSION['user_id'] ?? 1);

try {
    // the class of the change (Chart of Accounts > Class List): every adjusting journal entry carries it
    $classId = water_required_class($_POST['class_id'] ?? null, 'the batch change');
    $pdo->beginTransaction();

    $placeholders = implode(',', array_fill(0, count($bill_ids), '?'));

    $fetchStmt = $pdo->prepare("\n        SELECT\n            b.*,\n            bc.code,\n            bc.type,\n            bc.ar_account,\n            bc.income_account,\n            bc.cogs_account,\n            bc.asset_account,\n            bc.cost_price\n        FROM one_time_bills b\n        JOIN bill_codes bc ON bc.code_id = b.bill_code_id\n        WHERE b.bill_id IN ({$placeholders})\n        FOR UPDATE\n    ");
    $fetchStmt->execute($bill_ids);
    $bills = $fetchStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$bills) {
        throw new Exception("Selected bills were not found.");
    }

    $updateSql = $update_dates
        ? "UPDATE one_time_bills
           SET status = :status,
               due_date = :due_date,
               term_days = :term_days,
               disconnection_date = :disconnection_date,
               updated_at = NOW()
           WHERE bill_id = :bill_id"
        : "UPDATE one_time_bills SET status = :status, updated_at = NOW() WHERE bill_id = :bill_id";

    $updateStmt = $pdo->prepare($updateSql);

    $affected = 0;

    foreach ($bills as $bill) {
        $oldStatusActive = $bill['status'] !== 'cancelled';
        $newStatusActive = $status !== 'cancelled';

        $isInventory = (($bill['type'] ?? '') === 'Inventory');
        $qty = $isInventory ? (float)($bill['quantity'] ?? 1) : 0.00;
        $unitCost = $isInventory ? (float)($bill['inventory_unit_cost'] ?? 0) : 0.00;
        $costTotal = $isInventory ? (float)($bill['inventory_total_cost'] ?? ($qty * $unitCost)) : 0.00;

        if ($isInventory) {
            otb_assert_inventory_item(['type' => $bill['type'], 'code' => $bill['code'], 'item_id' => otb_bill_code_item($pdo, (int)$bill['bill_code_id'])['item_id'] ?? null]);

            if (!$oldStatusActive && $newStatusActive) {
                $availableQty = otb_available_stock($pdo, $bill['bill_code_id']);
                if ($qty > $availableQty) {
                    throw new Exception("Not enough inventory quantity balance to reactivate bill #{$bill['bill_id']}. Available: " . number_format($availableQty, 2));
                }

                otb_add_stock_movement($pdo, $bill['bill_code_id'], 'ADJUSTMENT', $bill['bill_id'], date('Y-m-d'), 0, $qty, $unitCost, "Batch status reactivation deducted stock for bill #{$bill['bill_id']}", $created_by);
            } elseif ($oldStatusActive && !$newStatusActive) {
                otb_add_stock_movement($pdo, $bill['bill_code_id'], 'ADJUSTMENT', $bill['bill_id'], date('Y-m-d'), $qty, 0, $unitCost, "Batch cancellation restored stock for bill #{$bill['bill_id']}", $created_by);
            }
        }

        $oldAccountingAmount = $oldStatusActive ? (float)$bill['total_amount'] : 0.00;
        $newAccountingAmount = $newStatusActive ? (float)$bill['total_amount'] : 0.00;
        $amountDelta = round($newAccountingAmount - $oldAccountingAmount, 2);

        $oldAccountingCost = ($isInventory && $oldStatusActive) ? $costTotal : 0.00;
        $newAccountingCost = ($isInventory && $newStatusActive) ? $costTotal : 0.00;
        $costDelta = round($newAccountingCost - $oldAccountingCost, 2);

        WaterPosting::withClass($classId, fn() => otb_create_adjustment_journal($pdo, date('Y-m-d'), $bill, $bill['member_id'], $bill['bill_id'], $amountDelta, $costDelta, 'Batch edit'));

        $params = [
            ':status' => $status,
            ':bill_id' => $bill['bill_id'],
        ];

        if ($update_dates) {
            $billDate = new DateTimeImmutable((string)$bill['bill_date']);
            $billTermDays = 0;
            $billDisconnectionDate = null;

            if ($due_date !== null) {
                $dueDateObject = new DateTimeImmutable($due_date);
                if ($dueDateObject < $billDate) {
                    throw new Exception("Due date cannot be earlier than the issue date of bill #{$bill['bill_id']}.");
                }
                $billTermDays = (int)$billDate->diff($dueDateObject)->days;
                if ($billTermDays > 3650) {
                    throw new Exception("Due date for bill #{$bill['bill_id']} cannot be more than 10 years after its issue date.");
                }

                $disconnectionDays = max(0, (int)($bill['disconnection_days'] ?? 0));
                if ($disconnectionDays > 0) {
                    $billDisconnectionDate = $dueDateObject
                        ->modify('+' . $disconnectionDays . ' days')
                        ->format('Y-m-d');
                }
            }

            $params[':due_date'] = $due_date;
            $params[':term_days'] = $billTermDays;
            $params[':disconnection_date'] = $billDisconnectionDate;
        }

        $updateStmt->execute($params);
        $affected += $updateStmt->rowCount();
    }

    $pdo->commit();

    $label = ucfirst($status);
    $_SESSION['success_msg'] = "Batch update complete! {$affected} bill(s) marked as \"{$label}\". Inventory/accounting adjustments were applied when needed.";

} catch (Exception $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    $_SESSION['error_msg'] = $e->getMessage();
    error_log("Batch Edit One Time Bill Error: " . $e->getMessage());
}

header("Location: ../views/one_time_billing.php");
exit();
