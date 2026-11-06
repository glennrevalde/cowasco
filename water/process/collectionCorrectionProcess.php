<?php
require_once __DIR__ . '/../database/config.php';

require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/accessControlHelpers.php';
require_once __DIR__ . '/../common/paymentService.php';

function ccp_redirect(string $message, bool $success = false): void
{
    $_SESSION[$success ? 'success_msg' : 'error_msg'] = $message;
    header('Location: ../views/collection_corrections.php');
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        throw new RuntimeException('Invalid request method.');
    }

    $pdo = getDB();
    requireProcessAccess($pdo, 'collection_corrections');
/* BUSINESS_MUTATION_GUARD_START */
require_once __DIR__ . '/../common/businessRequestGuard.php';
business_require_mutation(getDB(), '../views/collection_corrections.php', ['action' => 'collection']);
/* BUSINESS_MUTATION_GUARD_END */

    if (!verifyCsrf((string)($_POST['csrf_token'] ?? ''))) {
        throw new RuntimeException('Your form session expired. Refresh the page and try again.');
    }

    $userId = (int)($_SESSION['operator_id'] ?? $_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        throw new RuntimeException('Your operator session has expired.');
    }

    $receiptNo = trim((string)($_POST['receipt_no'] ?? ''));
    $action = trim((string)($_POST['action'] ?? ''));
    $businessDate = trim((string)($_POST['business_date'] ?? date('Y-m-d')));
    $reason = trim((string)($_POST['reason'] ?? ''));

    if ($receiptNo === '') {
        throw new InvalidArgumentException('Select a collection transaction first.');
    }
    if (!in_array($action, ['error_correction', 'refund'], true)) {
        throw new InvalidArgumentException('Invalid collection correction action.');
    }

    $serviceAction = $action === 'refund' ? 'refund' : 'reversal';
    // the class of the correction (Chart of Accounts > Class List), kept with it in the audit trail
    $classId = water_required_class($_POST['class_id'] ?? null, $action === 'refund' ? 'the refund' : 'the error correction');
    $result = WaterPosting::withClass($classId, fn() => ps_reverse_or_refund_payment(
        $pdo,
        $receiptNo,
        $serviceAction,
        $reason,
        $businessDate,
        $userId
    ));

    $label = $action === 'refund' ? 'Refund' : 'Error correction';
    $journal = trim((string)($result['reversal_journal_voucher_id'] ?? ''));
    $message = $label . ' completed for receipt ' . $receiptNo . '. The original allocations were restored and an opposite journal entry was created.';
    if ($journal !== '') {
        $message .= ' Reversal journal: ' . $journal . '.';
    }
    if ($action === 'error_correction') {
        $message .= ' The cashier may now collect the corrected transaction normally.';
    }

    ccp_redirect($message, true);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Collection correction error: ' . $e->getMessage());
    ccp_redirect($e instanceof PDOException
        ? 'The collection correction could not be completed because the database rejected the transaction. No correction was saved.'
        : $e->getMessage());
}
