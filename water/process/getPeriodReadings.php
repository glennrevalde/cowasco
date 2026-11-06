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

$period_id = (int)($_GET['period_id'] ?? 0);
if (!$period_id) {
    echo json_encode(['readings' => []]);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT br.*, m.rc_id, m.pipe_size_id, ps.size_code AS current_pipe_size_code, ps.size_label AS current_pipe_size_label,
               CONCAT_WS(', ', NULLIF(m.lastname, ''), NULLIF(m.firstname, '')) AS full_name,
               COALESCE(NULLIF(m.account_number, ''), CAST(m.pkey AS CHAR)) AS account_number,
               bp.status AS period_status,
               (SELECT COUNT(*) FROM bill_readings lx JOIN bill_periods lp ON lp.period_id = lx.period_id
                 WHERE lx.member_id = br.member_id AND lp.start_date > bp.start_date
                   AND COALESCE(lx.bill_lifecycle_status, 'active') <> 'deleted') AS later_readings
        FROM bill_readings br
        JOIN bill_periods bp ON bp.period_id = br.period_id
        JOIN water_accounts m ON br.member_id = m.pkey
        LEFT JOIN water_pipe_sizes ps ON ps.pipe_size_id = m.pipe_size_id
        WHERE br.period_id = ?
        ORDER BY br.updated_at DESC
    ");
    $stmt->execute([$period_id]);
    $readings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($readings as &$reading) {
        $reading['prev_reading'] = isset($reading['prev_reading'])
            ? water_rate_normalize_consumption($reading['prev_reading'])
            : 0;
        $reading['pres_reading'] = isset($reading['pres_reading'])
            ? water_rate_normalize_consumption($reading['pres_reading'])
            : 0;
        $reading['consumption'] = isset($reading['consumption'])
            ? water_rate_normalize_consumption($reading['consumption'])
            : 0;

        $details = water_snapshot_details_for_reading($reading);

        $reading['free_water_m3'] = $details['free_m3'];
        $reading['base_charge'] = $details['base_charge'];
        $reading['discount_amount'] = $details['discount_amount'];
        $reading['amount_due'] = $details['amount_due'];
        $reading['discount'] = $details['discount'];
        $reading['pipe_size_id'] = (int)($details['pipe_size_id'] ?? $reading['pipe_size_id'] ?? 0);
        $reading['pipe_size'] = $details['pipe_size'] ?? [
            'size_code' => $reading['current_pipe_size_code'] ?? '',
            'size_label' => $reading['current_pipe_size_label'] ?? '',
        ];
        $reading['tier_breakdown'] = $details['gross_lines'];
        $reading['snapshot_version'] = (int)($reading['snapshot_version'] ?? 0);
        $reading['snapshot_warning'] = $details['snapshot_warning'] ?? null;
        // Edit / Delete on the recorded readings (version 46): only a reading not posted, not paid, of an open period
        // and not followed by a later reading of the member (whose previous reading came from it)
        $posted = (int)($reading['is_billed'] ?? 0) === 1 || (int)($reading['is_paid'] ?? 0) === 1
            || strtolower((string)($reading['bill_lifecycle_status'] ?? 'active')) !== 'active';
        $reading['later_readings'] = (int)($reading['later_readings'] ?? 0);
        $reading['draft_editable'] = !$posted && ($reading['period_status'] ?? '') === 'open' && $reading['later_readings'] === 0;
        $reading['lock_reason'] = $posted ? 'Posted: corrected through Billing Adjustments'
            : (($reading['period_status'] ?? '') !== 'open' ? 'The period is closed'
            : ($reading['later_readings'] > 0 ? 'A later reading of the account exists' : ''));
    }
    unset($reading);

    echo json_encode(['readings' => $readings]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
