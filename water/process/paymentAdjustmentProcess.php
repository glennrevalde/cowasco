<?php
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/accessControlHelpers.php';
require_once __DIR__ . '/../common/superAdminGuard.php';
require_once __DIR__ . '/../common/paymentService.php';

function pa_redirect(string $message, bool $success = false): void {
    $_SESSION[$success ? 'success_msg' : 'error_msg'] = $message;
    header('Location: ../views/billing_adjustments.php');
    exit;
}
try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') throw new Exception('Invalid request method.');
    $pdo = getDB();
    requireProcessAccess($pdo, 'billing_adjustments');
/* BUSINESS_MUTATION_GUARD_START */
require_once __DIR__ . '/../common/businessRequestGuard.php';
business_require_mutation(getDB(), '../views/billing_adjustments.php', ['action' => 'collection']);
/* BUSINESS_MUTATION_GUARD_END */
    wb_require_super_admin('process');
    if (!verifyCsrf((string)($_POST['csrf_token'] ?? ''))) throw new Exception('Your form session expired. Refresh the page and try again.');
    $userId = (int)($_SESSION['operator_id'] ?? $_SESSION['user_id'] ?? 0);
    if ($userId <= 0) throw new Exception('Your session has expired.');
    $action = (string)($_POST['action'] ?? '');
    $date = (string)($_POST['business_date'] ?? date('Y-m-d'));
    $reason = trim((string)($_POST['reason'] ?? ''));
    // the class of the adjustment (Chart of Accounts > Class List): the documents it posts carry it
    $classId = water_required_class($_POST['class_id'] ?? null, in_array($action, ['payment_reversal', 'payment_refund'], true) ? 'the reversal or refund' : 'the bill adjustment');
    if (in_array($action, ['payment_reversal','payment_refund'], true)) {
        $result = WaterPosting::withClass($classId, fn() => ps_reverse_or_refund_payment($pdo, trim((string)($_POST['receipt_no'] ?? '')), $action === 'payment_refund' ? 'refund' : 'reversal', $reason, $date, $userId));
        pa_redirect('Payment ' . $result['status'] . ' successfully. The receivable balances and journal were restored through a controlled reversal.', true);
    }
    if (in_array($action, ['bill_debit','bill_credit'], true)) {
        $result = WaterPosting::withClass($classId, fn() => ps_adjust_bill($pdo, (string)($_POST['bill_type'] ?? ''), (int)($_POST['bill_id'] ?? 0), $action === 'bill_credit' ? 'credit' : 'debit', $_POST['amount'] ?? 0, $reason, $date, $userId));
        pa_redirect('Bill adjustment posted. New bill total: ' . number_format((float)$result['new_total'], 2), true);
    }
    throw new Exception('Unsupported adjustment action.');
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    error_log('Payment/bill adjustment error: ' . $e->getMessage());
    pa_redirect($e instanceof PDOException
        ? 'The adjustment could not be completed because the database rejected the transaction. No adjustment was saved.'
        : $e->getMessage());
}
