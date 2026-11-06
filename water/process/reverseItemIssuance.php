<?php
require_once __DIR__ . '/../database/config.php';

require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/accessControlHelpers.php';
require_once __DIR__ . '/../common/systemDataHelpers.php';
require_once __DIR__ . '/inventoryIssuanceAccountingHelpers.php';

$pdo = getDB();
requireProcessAccess($pdo, 'item_issuance');
/* BUSINESS_MUTATION_GUARD_START */
require_once __DIR__ . '/../common/businessRequestGuard.php';
business_require_mutation(getDB(), '../views/item_issuance.php', ['action' => 'inventory']);
/* BUSINESS_MUTATION_GUARD_END */

function reverse_issue_back(string $message, string $type = 'error'): void
{
    $_SESSION['issuance_flash'] = [$type, $message];
    header('Location: ../views/item_issuance.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    reverse_issue_back('POST required.');
}
if (!verifyCsrf((string)($_POST['csrf_token'] ?? ''))) {
    reverse_issue_back('Invalid request token.');
}

$issuanceId = (int)($_POST['issuance_id'] ?? 0);
$reason = trim((string)($_POST['reason'] ?? ''));
if ($issuanceId <= 0 || sys_text_length($reason) < 5 || sys_text_length($reason) > 255) {
    reverse_issue_back('Select an issuance and enter a reversal reason from 5 to 255 characters.');
}

try {
    $pdo->beginTransaction();

    $selectHeader = $pdo->prepare("
        SELECT *
        FROM inventory_issuances
        WHERE issuance_id = ?
        LIMIT 1
        FOR UPDATE
    ");
    $selectHeader->execute([$issuanceId]);
    $header = $selectHeader->fetch(PDO::FETCH_ASSOC);
    if (!$header) {
        throw new RuntimeException('Issuance not found.');
    }
    if ((string)$header['status'] !== 'POSTED') {
        throw new RuntimeException('Issuance is already reversed.');
    }
    if (empty($header['txn_id']) && empty($header['journal_head_id'])) {
        throw new RuntimeException('The original issuance has no accounting document and cannot be reversed safely.');
    }

    $selectItems = $pdo->prepare("
        SELECT *
        FROM inventory_issuance_items
        WHERE issuance_id = ?
        ORDER BY code_id
        FOR UPDATE
    ");
    $selectItems->execute([$issuanceId]);
    $items = $selectItems->fetchAll(PDO::FETCH_ASSOC);
    if (!$items) {
        throw new RuntimeException('Issuance has no item details.');
    }

    $userId = sys_operator_id();
    $reversalDate = date('Y-m-d');
    $total = 0.0;
    foreach ($items as $item) {
        $quantity = round((float)$item['quantity'], 2);
        if ($quantity <= 0) {
            throw new RuntimeException('An issuance item has an invalid quantity.');
        }
        $total += round((float)$item['line_cost'], 2);
    }

    // the inventory adjustment of the issuance is voided: the stock comes back, the ledger lines are removed
    $voucher = issuance_create_reversal_journal(
        $pdo,
        $issuanceId,
        (string)$header['issuance_no'],
        $reason,
        $total
    );

    $updateHeader = $pdo->prepare("
        UPDATE inventory_issuances
        SET
            status = 'REVERSED',
            reversal_reason = ?,
            reversed_at = NOW(),
            reversed_by = ?,
            reversal_journal_head_id = ?,
            reversal_journal_entry_id = ?
        WHERE issuance_id = ?
          AND status = 'POSTED'
    ");
    $updateHeader->execute([
        $reason,
        $userId,
        $voucher['journal_head_id'],
        $voucher['journal_entry_id'],
        $issuanceId,
    ]);
    if ($updateHeader->rowCount() !== 1) {
        throw new RuntimeException('The issuance state changed before reversal could complete.');
    }

    sys_audit($pdo, 'REVERSE_ITEM_ISSUANCE', 'inventory_issuances', $issuanceId, [
        'issuance_no' => $header['issuance_no'],
        'reason' => $reason,
        'original_journal_entry_id' => $header['journal_entry_id'],
        'reversal_journal_entry_id' => $voucher['journal_entry_id'],
        'total_cost' => $voucher['total'],
    ]);

    $pdo->commit();
    reverse_issue_back(
        'Issuance ' . $header['issuance_no'] . ' reversed. The stock was put back into the cooperative inventory and its inventory adjustment was voided (' .
        $voucher['journal_entry_id'] . ').',
        'success'
    );
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    reverse_issue_back('Reversal failed: ' . $error->getMessage());
}
