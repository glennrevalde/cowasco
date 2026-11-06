<?php
/**
 * Secure batch meter-reading entry.
 *
 * Each row is validated against the selected street/zone, locked by its real
 * member/period ownership, and saved with a server-derived previous reading.
 * Closed periods and billed/paid/deleted readings are immutable.
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

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    $body = $_POST;
    $body['readings'] = json_decode((string)($_POST['readings'] ?? '[]'), true);
}

$periodId = (int)($body['period_id'] ?? 0);
$readings = $body['readings'] ?? [];
$filterType = strtolower(trim((string)($body['filter_type'] ?? '')));
$filterValue = trim((string)($body['filter_value'] ?? $body['street'] ?? ''));
$encodedBy = (string)($_SESSION['username'] ?? $_SESSION['user_name'] ?? 'system');
$userId = (int)($_SESSION['user_id'] ?? 0);

if ($periodId <= 0 || !is_array($readings) || count($readings) === 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A billing period and at least one reading are required.']);
    exit;
}
if (count($readings) > 500) {
    http_response_code(413);
    echo json_encode(['success' => false, 'message' => 'A batch may contain at most 500 readings.']);
    exit;
}
if (!in_array($filterType, ['street', 'zone'], true) || $filterValue === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'A valid batch street or zone filter is required.']);
    exit;
}

// Use a deterministic member lock order to reduce deadlock risk when two
// batches touch overlapping member sets in different browser row orders.
$readings = array_values($readings);
usort($readings, static function ($left, $right): int {
    return ((int)($left['member_id'] ?? 0)) <=> ((int)($right['member_id'] ?? 0));
});

$pdo = getDB();
$saved = 0;
$skipped = 0;
$errors = [];
$results = [];
$seenMembers = [];

try {
    if ($userId <= 0) { throw new RuntimeException('Your session is missing a valid operator identifier.'); }
    $pdo->beginTransaction();
    $period = wr_lock_open_period($pdo, $periodId);

    foreach ($readings as $index => $row) {
        $memberId = (int)($row['member_id'] ?? 0);
        $savepoint = 'reading_row_' . $index;
        $pdo->exec('SAVEPOINT ' . $savepoint);

        try {
            if ($memberId <= 0) {
                throw new InvalidArgumentException('A batch row contains an invalid member ID.');
            }
            if (isset($seenMembers[$memberId])) {
                throw new InvalidArgumentException("Member #{$memberId} appears more than once in this batch.");
            }
            $seenMembers[$memberId] = true;

            $present = $row['pres_reading'] ?? null;
            if ($present === null || $present === '') {
                $skipped++;
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                continue;
            }

            $expectedReadingId = isset($row['reading_id']) && $row['reading_id'] !== ''
                ? (int)$row['reading_id']
                : null;

            $result = wr_save_draft_reading(
                $pdo,
                $period,
                $memberId,
                $present,
                'BATCH',
                $encodedBy,
                $userId,
                $expectedReadingId,
                ['type' => $filterType, 'value' => $filterValue]
            );

            $results[] = $result;
            $saved++;
            $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
        } catch (Throwable $rowError) {
            $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
            $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            $skipped++;
            $errors[] = "Member #{$memberId}: " . $rowError->getMessage();
        }
    }

    $pdo->commit();

    if ($saved === 0 && $errors) {
        http_response_code(422);
    }
    echo json_encode([
        'success' => $saved > 0,
        'message' => "{$saved} reading(s) saved, {$skipped} skipped.",
        'saved' => $saved,
        'skipped' => $skipped,
        'errors' => array_values(array_unique($errors)),
        'results' => $results,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
