<?php
/**
 * Posted water bills are immutable. This endpoint now returns the stored,
 * integrity-checked snapshot for display and refuses preview recomputation from
 * current setup tables.
 */
require_once __DIR__ . '/../database/config.php';
require_once '../database/config.php';
require_once __DIR__ . '/accessControlHelpers.php';
$__processGuardPdo = getDB(); requireProcessAccess($__processGuardPdo); unset($__processGuardPdo);
/* BUSINESS_MUTATION_GUARD_START */
require_once __DIR__ . '/../common/businessRequestGuard.php';
business_require_mutation(getDB(), '../views/billing_adjustments.php', ['json' => true]);
/* BUSINESS_MUTATION_GUARD_END */
require_once '../common/superAdminGuard.php';
require_once '../common/waterBillingSnapshotHelpers.php';
header('Content-Type: application/json');

try {
    if (!wb_is_super_admin()) { throw new RuntimeException('Unauthorized.'); }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { throw new RuntimeException('Invalid request method.'); }
    $billId = (int)($_POST['bill_id'] ?? 0);
    if ($billId <= 0) { throw new InvalidArgumentException('Invalid water bill selected.'); }

    $pdo = getDB();
    $stmt = $pdo->prepare('SELECT * FROM bill_readings WHERE reading_id = ? LIMIT 1');
    $stmt->execute([$billId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { throw new RuntimeException('Water bill was not found.'); }

    $details = water_snapshot_details_for_reading($row);
    echo json_encode([
        'success' => true,
        'editable' => false,
        'message' => 'Posted water readings and amounts are immutable. Use a separately approved adjustment/reversal workflow instead of recalculating historical bills.',
        'bill_id' => $billId,
        'member_id' => (int)$row['member_id'],
        'prev_reading' => (int)$row['prev_reading'],
        'pres_reading' => (int)$row['pres_reading'],
        'consumption' => (int)$details['consumption'],
        'amount_due' => round((float)$details['amount_due'], 2),
        'base_charge' => round((float)$details['base_charge'], 2),
        'discount_amount' => round((float)$details['discount_amount'], 2),
        'snapshot_version' => (int)($row['snapshot_version'] ?? 0),
        'snapshot_warning' => $details['snapshot_warning'] ?? null,
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
