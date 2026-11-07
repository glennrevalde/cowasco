<?php
/**
 * process/previewBillCorrection.php
 *
 * The bill editor of Billing Adjustments asks here what a correction would make of the bill while it is typed:
 * the corrected reading, consumption, tariff lines and amount of a water bill, or the quantity, amount, due date
 * and schedule of another bill. Computed with the functions the save uses (bl_water_correction(),
 * bl_other_correction()); nothing is written. The same rights as the save: Billing Adjustments, a Super
 * Administrator, the form's token.
 *
 * POST: csrf_token, bill (e.g. "one_time-8"), pres_reading | quantity | total_amount, due_date
 * Returns: {"ok": true, "bill": {...}} or {"ok": false, "error": "..."}
 */
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/accessControlHelpers.php';
require_once __DIR__ . '/../common/superAdminGuard.php';
require_once __DIR__ . '/../common/billEditorHelpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$respond = static function (int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        $respond(405, ['ok' => false, 'error' => 'Use POST.']);
    }
    $pdo = getDB();
    requireProcessAccess($pdo, 'billing_adjustments');
    if (!wb_is_super_admin()) {
        $respond(403, ['ok' => false, 'error' => 'Unauthorized. Correcting a bill requires a database-designated Super Administrator assignment.']);
    }
    if (!verifyCsrf((string)($_POST['csrf_token'] ?? ''))) {
        $respond(403, ['ok' => false, 'error' => 'Your form session expired. Refresh the page and try again.']);
    }
    $key = be_parse_key((string)($_POST['bill'] ?? ''));
    if ($key === null) {
        $respond(400, ['ok' => false, 'error' => 'Choose a bill to correct.']);
    }
    [$type, $id] = $key;
    $bill = be_preview($pdo, $type, $id, [
        'pres_reading' => $_POST['pres_reading'] ?? null,
        'quantity' => $_POST['quantity'] ?? null,
        'total_amount' => $_POST['total_amount'] ?? null,
        'due_date' => $_POST['due_date'] ?? null,
    ]);
    $respond(200, ['ok' => true, 'bill' => $bill]);
} catch (PDOException $e) {
    error_log('Bill correction preview: ' . $e->getMessage());
    $respond(500, ['ok' => false, 'error' => 'The corrected bill could not be worked out. Please try again.']);
} catch (Throwable $e) {
    // the reason a correction would be refused (the same message the save gives)
    $respond(200, ['ok' => false, 'error' => $e->getMessage()]);
}
