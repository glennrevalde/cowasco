<?php
require_once __DIR__ . '/../database/config.php';

require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/accessControlHelpers.php';
require_once __DIR__ . '/../common/systemDataHelpers.php';
require_once __DIR__ . '/inventoryCostingHelpers.php';
require_once __DIR__ . '/inventoryIssuanceAccountingHelpers.php';
require_once __DIR__ . '/organizationUnitHelpers.php';

$pdo = getDB();
requireProcessAccess($pdo, 'item_issuance');
/* BUSINESS_MUTATION_GUARD_START */
require_once __DIR__ . '/../common/businessRequestGuard.php';
business_require_mutation(getDB(), '../views/item_issuance.php', ['action' => 'inventory']);
/* BUSINESS_MUTATION_GUARD_END */

function issuance_back(string $message, string $type = 'error'): void
{
    $_SESSION['issuance_flash'] = [$type, $message];
    header('Location: ../views/item_issuance.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    issuance_back('POST required.');
}
if (!verifyCsrf((string)($_POST['csrf_token'] ?? ''))) {
    issuance_back('Invalid request token.');
}

$date = trim((string)($_POST['issuance_date'] ?? ''));
$recipient = trim((string)($_POST['recipient'] ?? ''));
$department = trim((string)($_POST['department'] ?? ''));
$purpose = trim((string)($_POST['purpose'] ?? ''));
$expenseAccountId = (int)($_POST['expense_account_id'] ?? 0);
$codes = (array)($_POST['code_id'] ?? []);
$quantities = (array)($_POST['quantity'] ?? []);

$dateValue = DateTime::createFromFormat('Y-m-d', $date);
if (!$dateValue || $dateValue->format('Y-m-d') !== $date || $date > date('Y-m-d')) {
    issuance_back('Enter a valid issuance date that is not in the future.');
}
if ($recipient === '' || sys_text_length($recipient) > 150) {
    issuance_back('Recipient is required and must not exceed 150 characters.');
}
if (sys_text_length($department) > 120) {
    issuance_back('Department must not exceed 120 characters.');
}
if ($purpose === '' || sys_text_length($purpose) > 255) {
    issuance_back('Purpose is required and must not exceed 255 characters.');
}
if ($expenseAccountId <= 0) {
    issuance_back('Select the expense or usage account for this issuance.');
}
// the class of the issuance (Chart of Accounts > Class List): its inventory adjustment carries it
try {
    $classId = water_required_class($_POST['class_id'] ?? null, 'the issuance');
} catch (Throwable $classError) {
    issuance_back($classError->getMessage());
}
if (count($codes) !== count($quantities)) {
    issuance_back('The submitted item rows are incomplete.');
}

$items = [];
foreach ($codes as $index => $code) {
    $codeId = (int)$code;
    $quantity = filter_var($quantities[$index] ?? null, FILTER_VALIDATE_FLOAT);

    if ($codeId <= 0 && ($quantity === false || (float)$quantity <= 0)) {
        continue;
    }
    if ($codeId <= 0 || $quantity === false || !is_finite((float)$quantity) || (float)$quantity <= 0) {
        issuance_back('Every item row must contain a valid item and positive quantity.');
    }

    $items[$codeId] = round(($items[$codeId] ?? 0) + (float)$quantity, 2);
}

if (!$items) {
    issuance_back('Add at least one item.');
}
ksort($items, SORT_NUMERIC);

try {
    if (!sys_table_exists($pdo, 'inventory_issuances') || !sys_table_exists($pdo, 'inventory_issuance_items')) {
        throw new RuntimeException('Run the water billing database migration first.');
    }

    $pdo->beginTransaction();
    $userId = sys_operator_id();

    issuance_assert_detail_account($pdo, $expenseAccountId, 'Issuance expense account');
    $siteId = issuance_item_site_id();

    try {
        $suffix = strtoupper(bin2hex(random_bytes(4)));
    } catch (Throwable $randomError) {
        $suffix = strtoupper(substr(sha1(uniqid((string)$userId, true)), 0, 8));
    }
    $issueNo = 'ISS-' . date('Ymd', strtotime($date)) . '-' . $suffix;

    $header = $pdo->prepare("
        INSERT INTO inventory_issuances
            (
                issuance_no,
                issuance_date,
                recipient,
                department,
                purpose,
                expense_account_id,
                status,
                site_id,
                created_by,
                created_at
            )
        VALUES (?, ?, ?, ?, ?, ?, 'POSTED', ?, ?, NOW())
    ");
    $header->execute([
        $issueNo,
        $date,
        $recipient,
        $department !== '' ? $department : null,
        $purpose,
        $expenseAccountId,
        $siteId,
        $userId,
    ]);
    $issuanceId = (int)$pdo->lastInsertId();

    // the bill code and the cooperative item it maps to (the item row is locked: its stock is about to move)
    $lockItem = $pdo->prepare("
        SELECT bc.code_id, bc.code, bc.description, bc.type, bc.item_id,
               i.code AS item_code, i.name AS item_name, i.item_type, i.is_active, i.avg_cost, i.asset_account_id
        FROM bill_codes bc
        LEFT JOIN items i ON i.id = bc.item_id
        WHERE bc.code_id = ?
        LIMIT 1
        FOR UPDATE
    ");
    $insertItem = $pdo->prepare("
        INSERT INTO inventory_issuance_items
            (issuance_id, code_id, item_id, quantity, unit_cost, line_cost, asset_account)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    $postingLines = [];
    $issuedItemsForAudit = [];

    foreach ($items as $codeId => $quantity) {
        $lockItem->execute([$codeId]);
        $item = $lockItem->fetch(PDO::FETCH_ASSOC);
        if (!$item || strcasecmp((string)$item['type'], 'Inventory') !== 0) {
            throw new RuntimeException('An item is missing or is not an inventory item.');
        }
        if (empty($item['item_id']) || empty($item['item_code'])) {
            throw new RuntimeException('Bill code ' . $item['code'] . ' is an inventory item but is not linked to an item of the Item List (Water Billing > Bill Codes).');
        }
        if ((int)$item['is_active'] !== 1 || !in_array((string)$item['item_type'], ['inventory', 'assembly'], true)) {
            throw new RuntimeException('Item ' . $item['item_code'] . ' (' . $item['code'] . ') is inactive or is not a stocked item.');
        }

        $balance = inv_balance_info($pdo, (int)$codeId);
        if ($quantity > (float)$balance['qty']) {
            throw new RuntimeException(
                $item['code'] . ' has only ' . number_format((float)$balance['qty'], 2) . ' available.'
            );
        }

        // the provisional cost at the item's average; replaced by the cost the inventory really took out
        $cost = inv_cost_for_outgoing(
            $quantity,
            (float)$balance['qty'],
            (float)$balance['total_cost']
        );
        $unitCost = round((float)$cost['unit_cost'], 2);
        $lineCost = round((float)$cost['total_cost'], 2);
        if ($lineCost <= 0) {
            $unitCost = round((float)$item['avg_cost'], 2);
            $lineCost = round($quantity * $unitCost, 2);
        }

        $insertItem->execute([
            $issuanceId,
            $codeId,
            (int)$item['item_id'],
            $quantity,
            $unitCost,
            $lineCost,
            (int)($item['asset_account_id'] ?? 0),
        ]);

        $postingLines[] = [
            'item_id' => (int)$item['item_id'],
            'qty' => $quantity,
            'description' => $item['code'] . ' - ' . $issueNo . ' to ' . $recipient,
        ];
        $issuedItemsForAudit[] = [
            'code_id' => $codeId,
            'code' => $item['code'],
            'item_id' => (int)$item['item_id'],
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'line_cost' => $lineCost,
            'asset_account' => (int)($item['asset_account_id'] ?? 0),
        ];
    }

    $voucher = WaterPosting::withClass($classId, fn() => issuance_create_journal(
        $pdo,
        $issuanceId,
        $issueNo,
        $date,
        $expenseAccountId,
        $postingLines,
        ['memo' => 'Material issuance ' . $issueNo . ' to ' . $recipient . ': ' . $purpose, 'site_id' => $siteId]
    ));

    // the lines carry the cost the inventory really took out (moving average at the time of posting)
    $fixLine = $pdo->prepare("UPDATE inventory_issuance_items SET unit_cost = ?, line_cost = ? WHERE issuance_id = ? AND item_id = ?");
    foreach ($voucher['movements'] as $itemId => $m) {
        $fixLine->execute([round((float)$m['unit_cost'], 2), round((float)$m['total_cost'], 2), $issuanceId, (int)$itemId]);
        foreach ($issuedItemsForAudit as &$a) {
            if ((int)$a['item_id'] === (int)$itemId) {
                $a['unit_cost'] = round((float)$m['unit_cost'], 2);
                $a['line_cost'] = round((float)$m['total_cost'], 2);
            }
        }
        unset($a);
    }

    $updateHeader = $pdo->prepare("
        UPDATE inventory_issuances
        SET journal_head_id = ?, journal_entry_id = ?, txn_id = ?
        WHERE issuance_id = ?
    ");
    $updateHeader->execute([
        $voucher['journal_head_id'],
        $voucher['journal_entry_id'],
        $voucher['txn_id'],
        $issuanceId,
    ]);

    sys_audit($pdo, 'CREATE_ITEM_ISSUANCE', 'inventory_issuances', $issuanceId, [
        'issuance_no' => $issueNo,
        'recipient' => $recipient,
        'expense_account_id' => $expenseAccountId,
        'txn_id' => $voucher['txn_id'],
        'journal_entry_id' => $voucher['journal_entry_id'],
        'total_cost' => $voucher['total'],
        'items' => $issuedItemsForAudit,
    ]);

    $pdo->commit();
    issuance_back(
        'Item issuance ' . $issueNo . ' posted. Stock was taken out of the cooperative inventory and inventory adjustment ' .
        $voucher['journal_entry_id'] . ' was posted to the ledger.',
        'success'
    );
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    issuance_back('Issuance failed: ' . $error->getMessage());
}
