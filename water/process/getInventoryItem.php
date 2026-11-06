<?php
require_once __DIR__ . '/../database/config.php';

require_once '../database/config.php';
/* PROCESS_ACCESS_GUARD_START */
require_once __DIR__ . '/accessControlHelpers.php';
$__processGuardPdo = getDB();
requireProcessAccess($__processGuardPdo);
unset($__processGuardPdo);
/* PROCESS_ACCESS_GUARD_END */


header('Content-Type: application/json');

$pdo = getDB();

$code_id = (int) ($_GET['code_id'] ?? 0);

if ($code_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid item.']);
    exit;
}

/*
 * An inventory bill code and the cooperative item it maps to (bill_codes.item_id): the stock on hand across
 * every site, the moving average cost and the accounts the cooperative inventory posts with. The bill code's
 * own COGS / asset accounts are informational only and are returned under their old names.
 */
try {
    $stmt = $pdo->prepare("
        SELECT
            bc.code_id,
            bc.code,
            bc.description,
            bc.item_id,
            COALESCE(i.avg_cost, bc.cost_price) AS cost_price,
            bc.cost_price AS bill_code_cost_price,
            bc.sales_price,
            i.code AS item_code,
            i.name AS item_name,
            i.unit AS item_unit,
            i.avg_cost AS average_unit_cost,
            COALESCE((SELECT SUM(s.qty_on_hand) FROM item_site_stock s WHERE s.item_id = i.id), 0) AS current_qty,
            bc.ar_account,
            ar.name AS ar_account_name,
            bc.income_account,
            inc.name AS income_account_name,
            bc.cogs_account,
            cogs.name AS cogs_account_name,
            bc.asset_account,
            ast.name AS asset_account_name,
            i.cogs_account_id AS item_cogs_account,
            icogs.name AS item_cogs_account_name,
            i.asset_account_id AS item_asset_account,
            iast.name AS item_asset_account_name
        FROM bill_codes bc
        LEFT JOIN items i ON i.id = bc.item_id
        LEFT JOIN accounts ar ON ar.id = bc.ar_account
        LEFT JOIN accounts inc ON inc.id = bc.income_account
        LEFT JOIN accounts cogs ON cogs.id = bc.cogs_account
        LEFT JOIN accounts ast ON ast.id = bc.asset_account
        LEFT JOIN accounts icogs ON icogs.id = i.cogs_account_id
        LEFT JOIN accounts iast ON iast.id = i.asset_account_id
        WHERE bc.code_id = :code_id
          AND bc.type = 'Inventory'
        LIMIT 1
    ");

    $stmt->execute([':code_id' => $code_id]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        echo json_encode(['success' => false, 'message' => 'Inventory item not found.']);
        exit;
    }
    if (empty($item['item_id'])) {
        echo json_encode(['success' => false, 'message' => 'Bill code ' . $item['code'] . ' is an inventory item but is not linked to an item of the Item List (Water Billing > Bill Codes).']);
        exit;
    }
    $item['current_qty'] = round((float)$item['current_qty'], 2);
    $item['average_unit_cost'] = round((float)$item['average_unit_cost'], 2);
    $item['cost_price'] = round((float)$item['cost_price'], 2);

    echo json_encode(['success' => true, 'item' => $item]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
