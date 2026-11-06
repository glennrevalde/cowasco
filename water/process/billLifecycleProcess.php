<?php
/**
 * process/billLifecycleProcess.php
 *
 * Two controlled actions:
 *   delete  soft-delete an unused bill (unchanged behaviour)
 *   edit    correct an UNCOLLECTED bill, void its journal voucher, and post a
 *           replacement voucher for the corrected amount
 *
 * A bill that has any live collection allocation is rejected before anything
 * is written, so a collected receivable can still only be changed through the
 * reversal/refund or debit/credit workflow.
 */

require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/accessControlHelpers.php';
require_once __DIR__ . '/../common/superAdminGuard.php';
require_once __DIR__ . '/../common/billLifecycleHelpers.php';
require_once __DIR__ . '/organizationUnitHelpers.php';

function blp_redirect(string $message, bool $success = false): void
{
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
business_require_mutation(getDB(), '../views/billing_adjustments.php', ['action' => 'billing']);
/* BUSINESS_MUTATION_GUARD_END */
    wb_require_super_admin('process');

    if (!verifyCsrf((string)($_POST['csrf_token'] ?? ''))) {
        throw new Exception('Your form session expired. Refresh the page and try again.');
    }

    $action = (string)($_POST['action'] ?? '');
    if (!in_array($action, ['delete', 'edit'], true)) {
        throw new Exception('Unsupported billing action.');
    }

    $type = (string)($_POST['bill_type'] ?? '');
    $id = (int)($_POST['bill_id'] ?? 0);
    if (!in_array($type, ['water', 'one_time', 'installment', 'recurring'], true)) {
        throw new Exception('Invalid bill type.');
    }

    $userId = (int)($_SESSION['operator_id'] ?? $_SESSION['user_id'] ?? 0);

    if ($action === 'delete') {
        $pdo->beginTransaction();
        bl_soft_delete_bill($pdo, $type, $id, $userId, trim((string)($_POST['delete_reason'] ?? '')));
        $pdo->commit();
        blp_redirect('Unused bill marked as deleted. Bills with payments must first use the controlled reversal/refund workflow.', true);
    }

    // the class of the correction (Chart of Accounts > Class List): the replacement journal entry carries it
    $classId = water_required_class($_POST['class_id'] ?? null, 'the bill correction');
    $pdo->beginTransaction();
    $result = WaterPosting::withClass($classId, fn() => bl_update_bill($pdo, $type, $id, [
        'reason' => (string)($_POST['reason'] ?? ''),
        'pres_reading' => $_POST['pres_reading'] ?? null,
        'total_amount' => $_POST['total_amount'] ?? null,
        'quantity' => $_POST['quantity'] ?? null,
        'due_date' => $_POST['due_date'] ?? null,
    ], $userId));
    $pdo->commit();

    $money = bl_money($result['old_amount']) . ' → ' . bl_money($result['new_amount']);

    // An unposted reading has no journal, so do not report voucher activity
    // that never happened.
    if (array_key_exists('posted', $result) && !$result['posted']) {
        blp_redirect(sprintf(
            'Draft reading #%d corrected: %s. It is not posted, so no journal entry was voided or created.',
            (int)$result['bill_id'],
            $money
        ), true);
    }

    // Quote the number the operator can actually find in the Journal Entry
    // register (Invoice No. 1024-R1), not the internal JV sequence id.
    $correctionNo = trim((string)($result['replacement_document_no'] ?? ''));
    $voidedCount = (int)$result['voided_count'];

    $summary = sprintf('Bill #%d corrected: %s.', (int)$result['bill_id'], $money);

    if ($voidedCount > 0) {
        $summary .= $voidedCount === 1
            ? ' The previous journal entry was voided'
            : sprintf(' The %d previous journal entries were voided', $voidedCount);
        $summary .= $correctionNo !== ''
            ? ', and a separate correction entry, Invoice No. ' . $correctionNo . ', now carries the corrected amount.'
            : '. The corrected amount is zero, so no correction entry was posted.';
        $summary .= ' The voided entry stays on file but is excluded from every total.';
    } elseif ($correctionNo !== '') {
        $summary .= ' A separate correction entry, Invoice No. ' . $correctionNo . ', now carries the corrected amount.';
    }

    blp_redirect($summary, true);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    error_log('Bill lifecycle error: ' . $e->getMessage());
    blp_redirect($e->getMessage());
}
