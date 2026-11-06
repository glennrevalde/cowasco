<?php
/** Register a receipt print only after the user explicitly starts printing. */
require_once __DIR__ . '/../database/config.php';

require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/accessControlHelpers.php';
require_once __DIR__ . '/../common/collectionReceiptHelpers.php';

header('Content-Type: application/json; charset=utf-8');

function receipt_print_response(int $status, bool $success, string $message): never
{
    http_response_code($status);
    echo json_encode(['success' => $success, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    receipt_print_response(405, false, 'Only POST requests are accepted.');
}

try {
    $pdo = getDB();
    requireProcessAccess($pdo, 'collection_receipt');

    if (!verifyCsrf((string)($_POST['csrf_token'] ?? ''))) {
        receipt_print_response(403, false, 'Your receipt page expired. Refresh it before printing.');
    }

    $receiptNo = trim((string)($_POST['receipt_no'] ?? ''));
    if ($receiptNo === '' || strlen($receiptNo) > 50) {
        receipt_print_response(422, false, 'A valid receipt number is required.');
    }

    $pdo->beginTransaction();
    $lock = $pdo->prepare('SELECT transaction_id FROM payment_transactions WHERE receipt_no = ? FOR UPDATE');
    $lock->execute([$receiptNo]);
    if (!$lock->fetchColumn()) {
        $pdo->rollBack();
        receipt_print_response(404, false, 'Receipt not found.');
    }

    cr_register_print($pdo, $receiptNo);
    $pdo->commit();
    receipt_print_response(200, true, 'Receipt print registered.');
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[registerReceiptPrint] ' . $e->getMessage());
    receipt_print_response(500, false, 'The receipt can still be printed, but the print counter could not be updated.');
}
