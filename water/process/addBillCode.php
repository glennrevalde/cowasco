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

require_once '../common/billingHelpers.php'; // Included for logAudit
require_once '../common/waterBillCodeProvisioningHelpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo = getDB(); 
    
    // Sanitize and collect base inputs
    $code        = strtoupper(trim(strip_tags($_POST['code'] ?? '')));
    $description = trim(strip_tags($_POST['description'] ?? ''));
    $type        = trim(strip_tags($_POST['type'] ?? ''));
    
    $modified_by = (int)($_SESSION['user_id'] ?? 1); 

    // Pricing
    $sales_price = !empty($_POST['sales_price']) ? (float)$_POST['sales_price'] : 0.00;
    $cost_price  = !empty($_POST['cost_price'])  ? (float)$_POST['cost_price']  : 0.00;
    $allow_member_discount = !empty($_POST['allow_member_discount']) ? 1 : 0;

    // GL Account logic (If empty, set to NULL to respect the foreign key constraints)
    $ar_account     = !empty($_POST['ar_account'])     ? (int)$_POST['ar_account']     : null;
    $income_account = !empty($_POST['income_account']) ? (int)$_POST['income_account'] : null;
    $cogs_account   = !empty($_POST['cogs_account'])   ? (int)$_POST['cogs_account']   : null;
    $asset_account  = !empty($_POST['asset_account'])  ? (int)$_POST['asset_account']  : null;
    // the item of the cooperative Item List an inventory bill code sells (bill_codes.item_id)
    $item_id        = !empty($_POST['item_id'])        ? (int)$_POST['item_id']        : null;

    if (empty($code) || empty($type)) {
        $_SESSION['error_msg'] = "Code and Bill Type are required fields.";
        header("Location: ../views/bill_codes.php");
        exit();
    }

    if ($code === 'WATER BILL') {
        try {
            $result = water_bill_code_ensure($pdo, $modified_by);
            $_SESSION['success_msg'] = !empty($result['created'])
                ? 'WATER BILL was created and configured automatically.'
                : 'WATER BILL already exists and is configured.';
        } catch (Throwable $e) {
            $_SESSION['error_msg'] = $e->getMessage();
        }
        header("Location: ../views/bill_codes.php");
        exit();
    }

    // STRICT LOGIC: Only "Inventory" items get COGS, Asset, and Cost assignments.
    // If it's a Service or Charge, clear these fields out to keep the database clean.
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
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO bill_codes 
            (code, description, type, ar_account, income_account, cogs_account, asset_account, item_id, cost_price, sales_price, allow_member_discount, modified_by, created_at, updated_at) 
            VALUES 
            (:code, :description, :type, :ar_account, :income_account, :cogs_account, :asset_account, :item_id, :cost_price, :sales_price, :allow_member_discount, :modified_by, NOW(), NOW())
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
            ':cost_price'     => $cost_price,
            ':sales_price'    => $sales_price,
            ':allow_member_discount' => $allow_member_discount,
            ':modified_by'    => $modified_by
        ]);

        $code_id = $pdo->lastInsertId();

        // --- AUDIT LOGGING ---
        logAudit($pdo, $modified_by, 'CREATE_BILL_CODE', 'bill_codes', (string)$code_id, json_encode([
            'code' => $code,
            'type' => $type,
            'item_id' => $item_id,
            'sales_price' => $sales_price,
            'allow_member_discount' => $allow_member_discount
        ]));

        $pdo->commit();
        $_SESSION['success_msg'] = "Bill Code '{$code}' added successfully!";
        
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        // Catch Duplicate Entry error (1062) for the UNIQUE constraint on `code`
        if ($e->getCode() == 23000) {
            $_SESSION['error_msg'] = "A Bill Code with the name '{$code}' already exists.";
        } else {
            $_SESSION['error_msg'] = "Database error. Failed to save code.";
            error_log("Add Bill Code Error: " . $e->getMessage());
        }
    }

    header("Location: ../views/bill_codes.php");
    exit();
} else {
    header("Location: ../views/bill_codes.php");
    exit();
}