<?php
require_once '../database/config.php';
/* PROCESS_ACCESS_GUARD_START */
require_once __DIR__ . '/accessControlHelpers.php';
$__processGuardPdo = getDB();
requireProcessAccess($__processGuardPdo);
unset($__processGuardPdo);
/* PROCESS_ACCESS_GUARD_END */

require_once '../common/customerLedgerHelpers.php';

header('Content-Type: application/json');

$pdo = getDB();
$memberId = (int)($_GET['member_id'] ?? 0);

try {
    if ($memberId <= 0) {
        echo json_encode([
            'success' => false,
            'error' => 'Member ID is required.'
        ]);
        exit;
    }

    $ledger = cl_fetch_customer_ledger($pdo, $memberId);

    if (!$ledger['member']) {
        echo json_encode([
            'success' => false,
            'error' => 'Member was not found.'
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'ledger' => $ledger
    ]);
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
