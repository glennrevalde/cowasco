<?php
require_once '../database/config.php';
/* PROCESS_ACCESS_GUARD_START */
require_once __DIR__ . '/accessControlHelpers.php';
$__processGuardPdo = getDB();
requireProcessAccess($__processGuardPdo);
unset($__processGuardPdo);
/* PROCESS_ACCESS_GUARD_END */

require_once '../common/waterRateBillingHelpers.php';
require_once '../common/waterBillingSnapshotHelpers.php';

header('Content-Type: application/json');
$pdo = getDB();

$member_id = (int)($_GET['member_id'] ?? 0);
if (!$member_id) {
    echo json_encode([]);
    exit;
}

try {
    $stmtBills = $pdo->prepare("
        SELECT br.reading_id, br.member_id, br.consumption, br.amount_due,
               br.snapshot_version, br.rate_code_id_snapshot, br.rate_code_snapshot,
               br.tariff_snapshot, br.discount_snapshot, br.calculation_snapshot,
               br.base_charge_snapshot, br.discount_amount_snapshot,
               br.snapshot_hash, br.snapshot_created_at,
               DATE(br.created_at) AS reading_date, bp.bp_code
        FROM bill_readings br
        JOIN bill_periods bp ON br.period_id = bp.period_id
        WHERE br.member_id = ?
          AND br.is_billed = 1
          AND br.is_paid = 0
          AND COALESCE(br.bill_lifecycle_status, 'active') <> 'deleted'
        ORDER BY bp.start_date ASC
    ");
    $stmtBills->execute([$member_id]);

    $results = [];
    foreach ($stmtBills->fetchAll(PDO::FETCH_ASSOC) as $reading) {
        $details = water_snapshot_details_for_reading($reading);

        $results[] = [
            'reading_id' => (int)$reading['reading_id'],
            'period_code' => (string)$reading['bp_code'],
            'consumption' => (int)$details['consumption'],
            'reading_date' => $reading['reading_date'],
            'amount_due' => (float)$details['amount_due'],
            'snapshot_version' => (int)($reading['snapshot_version'] ?? 0),
            'snapshot_warning' => $details['snapshot_warning'] ?? null,
        ];
    }

    echo json_encode($results);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
