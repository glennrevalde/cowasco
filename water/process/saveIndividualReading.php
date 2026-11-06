<?php
/**
 * Secure individual meter-reading entry.
 *
 * The server derives the previous reading from the chronological database
 * chain. Client-supplied previous readings and override flags are ignored.
 */

require_once '../database/config.php';
/* PROCESS_ACCESS_GUARD_START */
require_once __DIR__ . '/accessControlHelpers.php';
$__processGuardPdo = getDB();
requireProcessAccess($__processGuardPdo);
unset($__processGuardPdo);
/* PROCESS_ACCESS_GUARD_END */
/* BUSINESS_MUTATION_GUARD_START */
require_once __DIR__ . '/../common/businessRequestGuard.php';
business_require_mutation(getDB(), '../views/reading_entry.php', ['action' => 'billing', 'json' => true]);
/* BUSINESS_MUTATION_GUARD_END */

require_once '../common/waterReadingWorkflowHelpers.php';

require_once __DIR__ . '/../database/config.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$periodId = (int)($input['period_id'] ?? 0);
$memberId = (int)($input['member_id'] ?? 0);
$presentReading = $input['pres_reading'] ?? null;
$expectedReadingId = isset($input['reading_id']) && $input['reading_id'] !== ''
    ? (int)$input['reading_id']
    : null;
$username = (string)($_SESSION['username'] ?? $_SESSION['user_name'] ?? 'system');
$userId = (int)($_SESSION['user_id'] ?? 0);

$pdo = getDB();

try {
    if ($userId <= 0) { throw new RuntimeException('Your session is missing a valid operator identifier.'); }
    $pdo->beginTransaction();
    $period = wr_lock_open_period($pdo, $periodId);
    $result = wr_save_draft_reading(
        $pdo,
        $period,
        $memberId,
        $presentReading,
        'INDIVIDUAL',
        $username,
        $userId,
        $expectedReadingId,
        null
    );
    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => $result['created'] ? 'Meter reading saved.' : 'Meter reading updated.',
        'reading_id' => $result['reading_id'],
        'global_ref' => $result['global_ref_number'],
        'prev_reading' => $result['prev_reading'],
        'pres_reading' => $result['pres_reading'],
        'consumption' => $result['consumption'],
        'water_charge' => $result['base_charge'],
        'discount_amount' => $result['discount_amount'],
        'amount_due' => $result['amount_due'],
        'snapshot_hash' => $result['snapshot_hash'],
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $status = $e instanceof InvalidArgumentException ? 422 : 409;
    http_response_code($status);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
