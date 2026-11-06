<?php
require_once '../database/config.php';
/* PROCESS_ACCESS_GUARD_START */
require_once __DIR__ . '/accessControlHelpers.php';
require_once __DIR__ . '/../common/businessRequestGuard.php';
$__processGuardPdo = getDB();
// Read-only dependency shared by several business pages (audit F07): the caller
// names its page through ?context=..., limited to this allowlist.
business_require_read_access($__processGuardPdo, ['reading_history', 'billing_history'], 'reading_history');
unset($__processGuardPdo);
/* PROCESS_ACCESS_GUARD_END */

require_once '../common/waterRateBillingHelpers.php';
require_once '../common/waterBillingSnapshotHelpers.php';

header('Content-Type: application/json');
$pdo = getDB();

$q = trim((string)($_GET['q'] ?? ''));
$period_id = (int)($_GET['period_id'] ?? 0);

try {
    $sql = "
        SELECT br.*, m.rc_id, m.pipe_size_id, ps.size_code AS current_pipe_size_code, ps.size_label AS current_pipe_size_label,
               CONCAT_WS(', ', NULLIF(m.lastname, ''), NULLIF(m.firstname, '')) AS full_name,
               COALESCE(NULLIF(m.account_number, ''), CAST(m.pkey AS CHAR)) AS account_number,
               bp.bp_code, bp.start_date, bp.end_date
        FROM bill_readings br
        JOIN water_accounts m ON br.member_id = m.pkey
        LEFT JOIN water_pipe_sizes ps ON ps.pipe_size_id = m.pipe_size_id
        JOIN bill_periods bp ON br.period_id = bp.period_id
        WHERE 1 = 1
    ";
    $params = [];

    if ($period_id > 0) {
        $sql .= " AND br.period_id = ?";
        $params[] = $period_id;
    }

    if ($q !== '') {
        $sql .= " AND (CAST(m.pkey AS CHAR) LIKE ? OR m.account_number LIKE ? OR m.lastname LIKE ? OR m.firstname LIKE ? OR bp.bp_code LIKE ?)";
        $search = "%{$q}%";
        array_push($params, $search, $search, $search, $search, $search);
    }

    $sql .= " ORDER BY br.updated_at DESC LIMIT 500";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
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
    }
    unset($reading);

    echo json_encode(['readings' => $readings]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
