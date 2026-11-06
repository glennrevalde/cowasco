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
business_require_mutation(getDB(), '../views/bill_codes.php');
/* BUSINESS_MUTATION_GUARD_END */

require_once '../common/waterBillCodeProvisioningHelpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo = getDB();
    
    $code_id = filter_input(INPUT_POST, 'code_id', FILTER_VALIDATE_INT);
    
    if (!$code_id) {
        $_SESSION['error_msg'] = "Invalid Bill Code ID.";
        header("Location: ../views/bill_codes.php");
        exit();
    }

    $currentStmt = $pdo->prepare('SELECT code FROM bill_codes WHERE code_id = ? LIMIT 1');
    $currentStmt->execute([$code_id]);
    $currentCode = strtoupper(trim((string)$currentStmt->fetchColumn()));
    $isRequiredWaterBill = $currentCode === 'WATER BILL';

    // Sanitize and collect base inputs
    $code        = strtoupper(trim(strip_tags($_POST['code'] ?? '')));
    if ($isRequiredWaterBill) {
        $code = 'WATER BILL';
    }
    $description = trim(strip_tags($_POST['description'] ?? ''));
    $type        = trim(strip_tags($_POST['type'] ?? ''));
    
    $modified_by = (int)($_SESSION['user_id'] ?? 1); 

    // Pricing
    $sales_price = !empty($_POST['sales_price']) ? (float)$_POST['sales_price'] : 0.00;
    $cost_price  = !empty($_POST['cost_price'])  ? (float)$_POST['cost_price']  : 0.00;
    $allow_member_discount = !empty($_POST['allow_member_discount']) ? 1 : 0;

    // GL Account logic
    $ar_account     = !empty($_POST['ar_account'])     ? (int)$_POST['ar_account']     : null;
    $income_account = !empty($_POST['income_account']) ? (int)$_POST['income_account'] : null;
    $cogs_account   = !empty($_POST['cogs_account'])   ? (int)$_POST['cogs_account']   : null;
    $asset_account  = !empty($_POST['asset_account'])  ? (int)$_POST['asset_account']  : null;
    // the item of the cooperative Item List an inventory bill code sells (bill_codes.item_id)
    $item_id        = !empty($_POST['item_id'])        ? (int)$_POST['item_id']        : null;

    if ($isRequiredWaterBill || $code === 'WATER BILL') {
        $type = 'Service';
        $sales_price = 0.00;
        $cost_price = 0.00;
        $allow_member_discount = 1;
        $cogs_account = null;
        $asset_account = null;
        $item_id = null;
    }

    // STRICT LOGIC: Clean data if type was switched away from Inventory
    if ($type !== 'Inventory') {
        $cogs_account  = null;
        $asset_account = null;
        $cost_price    = 0.00;
        $item_id       = null;
    }

    if ($type === 'Inventory') {
        // an inventory bill code sells an item of the cooperative Item List: the stock, the cost and the
        // inventory accounts come from that item, so the link is required
        if (!$item_id) {
            $_SESSION['error_msg'] = "An Inventory bill code must be linked to an item of the Item List. Choose the item it sells.";
            header("Location: ../views/bill_codes.php");
            exit();
        }
        $itemStmt = $pdo->prepare("SELECT id, code, name, item_type, is_active FROM items WHERE id = ? LIMIT 1");
        $itemStmt->execute([$item_id]);
        $linkedItem = $itemStmt->fetch(PDO::FETCH_ASSOC);
        if (!$linkedItem || (int)$linkedItem['is_active'] !== 1 || !in_array((string)$linkedItem['item_type'], ['inventory', 'assembly'], true)) {
            $_SESSION['error_msg'] = "The chosen item is not an active inventory item of the Item List.";
            header("Location: ../views/bill_codes.php");
            exit();
        }
    }

    if ($type === 'Inventory') {
        $allow_member_discount = 0;
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE bill_codes 
            SET code           = :code, 
                description    = :description, 
                type           = :type, 
                ar_account     = :ar_account,
                income_account = :income_account,
                cogs_account   = :cogs_account,
                asset_account  = :asset_account,
                item_id        = :item_id,
                sales_price    = :sales_price,
                cost_price     = :cost_price,
                allow_member_discount = :allow_member_discount,
                modified_by    = :modified_by,
                updated_at     = NOW()
            WHERE code_id = :code_id
        ");
        
        $stmt->execute([
            ':code'           => $code,
            ':description'    => $description,
            ':type'           => $type,
            ':ar_account'     => $ar_account,
            ':income_account' => $income_account,
            ':cogs_account'   => $cogs_account,
            ':asset_account'  => $asset_account,
            ':item_id'        => $item_id,
            ':sales_price'    => $sales_price,
            ':cost_price'     => $cost_price,
            ':allow_member_discount' => $allow_member_discount,
            ':modified_by'    => $modified_by,
            ':code_id'        => $code_id
        ]);

        if ($code === 'WATER BILL') {
            water_bill_code_ensure($pdo, $modified_by);
        }

        $_SESSION['success_msg'] = "Bill Code '{$code}' updated successfully!";
        
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            $_SESSION['error_msg'] = "A Bill Code with the name '{$code}' already exists.";
        } else {
            $_SESSION['error_msg'] = "Database error. Failed to update code.";
            error_log("Edit Bill Code Error: " . $e->getMessage());
        }
    }

    header("Location: ../views/bill_codes.php");
    exit();
}