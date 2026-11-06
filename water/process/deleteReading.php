<?php
/**
 * Reading Entry / Reading History: deletes an unposted meter reading (a draft) - version 46.
 *
 * POST JSON {period_id, reading_id, reason, csrf_token}. The period must be open, the reading not posted (billed) and
 * not paid, and no later reading of the member may exist (its previous reading came from this one). A posted reading
 * is never deleted: it is corrected through Billing Adjustments. The reading is removed and the deletion is written
 * to the water audit log with the reading as it was and the reason.
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

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}
if (!Access::can('water.reading_entry', 'delete')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Your role may not delete meter readings (the Delete right on Reading Entry).']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}
$periodId = (int)($input['period_id'] ?? 0);
$readingId = (int)($input['reading_id'] ?? 0);
$reason = (string)($input['reason'] ?? '');
$userId = (int)($_SESSION['user_id'] ?? 0);

$pdo = getDB();
try {
    if ($userId <= 0) {
        throw new RuntimeException('Your session is missing a valid operator identifier.');
    }
    $pdo->beginTransaction();
    $period = wr_lock_open_period($pdo, $periodId);
    $row = wr_delete_draft_reading($pdo, $period, $readingId, $reason, $userId);
    $pdo->commit();
    echo json_encode([
        'success' => true,
        'message' => 'The reading of ' . (int)$row['pres_reading'] . ' m³ was deleted. The account can be read again for this period.',
        'reading_id' => $readingId,
        'member_id' => (int)$row['member_id'],
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $status = $e instanceof InvalidArgumentException ? 422 : 409;
    http_response_code($status);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
