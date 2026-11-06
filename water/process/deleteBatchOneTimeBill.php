<?php
/**
 * process/deleteBatchOneTimeBill.php
 * Batch deletes bills and reverses inventory/accounting where needed.
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

$pdo = getDB();

function redirect_batch_delete($message, $type = 'error')
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
    redirect_batch_delete("No valid bills selected for deletion.");
}

$created_by = (int)($_SESSION['user_id'] ?? 1);

try {
    $pdo->beginTransaction();

    $placeholders = implode(',', array_fill(0, count($bill_ids), '?'));

    $fetchStmt = $pdo->prepare("\n        SELECT\n            b.*,\n            bc.code,\n            bc.type,\n            bc.ar_account,\n            bc.income_account,\n            bc.cogs_account,\n            bc.asset_account,\n            bc.cost_price\n        FROM one_time_bills b\n        JOIN bill_codes bc ON bc.code_id = b.bill_code_id\n        WHERE b.bill_id IN ({$placeholders})\n        FOR UPDATE\n    ");
    $fetchStmt->execute($bill_ids);
    $bills = $fetchStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$bills) {
        throw new Exception("Selected bills were not found.");
    }

    foreach ($bills as $bill) {
        $isInventory = (($bill['type'] ?? '') === 'Inventory');
        $isActive = $bill['status'] !== 'cancelled';

        if ($isInventory && $isActive) {
            $qty = (float)($bill['quantity'] ?? 1);
            $unitCost = (float)$bill['cost_price'];

            if ($qty > 0) {
                otb_add_stock_movement(
                    $pdo,
                    $bill['bill_code_id'],
                    'ADJUSTMENT',
                    $bill['bill_id'],
                    date('Y-m-d'),
                    $qty,
                    0,
                    $unitCost,
                    "Batch delete restored inventory for bill #{$bill['bill_id']}",
                    $created_by
                );
            }
        }

        $amountDelta = $isActive ? -1 * (float)$bill['total_amount'] : 0.00;
        $costDelta = ($isInventory && $isActive)
            ? -1 * (float)($bill['inventory_total_cost'] ?? ((float)($bill['quantity'] ?? 1) * (float)$bill['cost_price']))
            : 0.00;

        otb_create_adjustment_journal($pdo, date('Y-m-d'), $bill, $bill['member_id'], $bill['bill_id'], $amountDelta, $costDelta, 'Batch delete');
    }

    $deleteStmt = $pdo->prepare("DELETE FROM one_time_bills WHERE bill_id IN ({$placeholders})");
    $deleteStmt->execute($bill_ids);
    $affected = $deleteStmt->rowCount();

    $pdo->commit();

    $_SESSION['success_msg'] = "{$affected} bill(s) successfully deleted. Inventory/accounting reversals were applied when needed.";

} catch (Exception $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    $_SESSION['error_msg'] = $e->getMessage();
    error_log("Batch Delete One Time Bill Error: " . $e->getMessage());
}

header("Location: ../views/one_time_billing.php");
exit();
