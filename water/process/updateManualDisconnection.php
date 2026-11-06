<?php
/**
 * process/updateManualDisconnection.php
 * Handles explicit manual disconnect/reconnect actions.
 */

require_once __DIR__ . '/../database/config.php';

require_once __DIR__ . '/../database/config.php';
/* PROCESS_ACCESS_GUARD_START */
require_once __DIR__ . '/accessControlHelpers.php';
$__processGuardPdo = getDB();
requireProcessAccess($__processGuardPdo);
unset($__processGuardPdo);
/* PROCESS_ACCESS_GUARD_END */
require_once __DIR__ . '/disconnectionHelpers.php';

$pdo = mdh_pdo();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../views/disconnection_management.php');
    exit;
}

$returnQ = trim((string)($_POST['return_q'] ?? ''));
$returnBillCodeId = max(0, (int)($_POST['return_bill_code_id'] ?? $_POST['bill_code_id'] ?? 0));
$returnParams = [];
if ($returnQ !== '') $returnParams['q'] = $returnQ;
if ($returnBillCodeId > 0) $returnParams['bill_code_id'] = $returnBillCodeId;
$returnUrl = '../views/disconnection_management.php' . ($returnParams ? '?' . http_build_query($returnParams) : '');

if (!verifyCsrf((string)($_POST['csrf_token'] ?? ''))) {
    $_SESSION['error_msg'] = 'Your form session expired. Refresh the page and try again.';
    header('Location: ' . $returnUrl);
    exit;
}

$action = strtolower(trim((string)($_POST['action'] ?? '')));
$userId = mdh_user_id();

try {
    $pdo->beginTransaction();

    if ($action === 'disconnect') {
        $sourceType = strtolower(trim((string)($_POST['source_type'] ?? '')));
        $sourceId = (int)($_POST['source_id'] ?? 0);
        $reason = trim((string)($_POST['reason'] ?? ''));

        if ($sourceType === '' || $sourceId <= 0) {
            throw new RuntimeException('Invalid bill selected for disconnection.');
        }

        mdh_manual_disconnect($pdo, $sourceType, $sourceId, $reason, $userId);
        $_SESSION['success_msg'] = 'Customer was manually marked as disconnected.';
    } elseif ($action === 'disconnect_bill_code') {
        $billCodeId = (int)($_POST['bill_code_id'] ?? 0);
        $reason = trim((string)($_POST['reason'] ?? ''));
        if ($billCodeId <= 0) {
            throw new RuntimeException('Select a specific bill code before using the bulk disconnection action.');
        }

        $result = mdh_manual_disconnect_bill_code($pdo, $billCodeId, $reason, $userId);
        $count = (int)($result['disconnected_count'] ?? 0);
        if ($count > 0) {
            $_SESSION['success_msg'] = $count . ' eligible member' . ($count === 1 ? '' : 's') . ' manually disconnected for the selected bill code.';
        } else {
            $_SESSION['success_msg'] = 'No active eligible members needed disconnection for the selected bill code.';
        }
    } elseif ($action === 'reconnect') {
        $memberId = (int)($_POST['member_id'] ?? 0);
        $reason = trim((string)($_POST['reason'] ?? ''));

        if ($memberId <= 0) {
            throw new RuntimeException('Invalid customer selected for reconnection.');
        }

        mdh_manual_reconnect($pdo, $memberId, $reason, $userId);
        $_SESSION['success_msg'] = 'Customer was manually marked as reconnected/active.';
    } else {
        throw new RuntimeException('Invalid action.');
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['error_msg'] = $e->getMessage();
}

header('Location: ' . $returnUrl);
exit;
