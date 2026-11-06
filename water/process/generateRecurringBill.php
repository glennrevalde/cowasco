<?php
/** Compatibility endpoint for the canonical recurring billing service. */
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/recurringBillingProcess.php';
require_once __DIR__ . '/organizationUnitHelpers.php';
$pdo = getDB();

if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/accessControlHelpers.php';
    requireProcessAccess($pdo, 'recurring_bill');
/* BUSINESS_MUTATION_GUARD_START */
require_once __DIR__ . '/../common/businessRequestGuard.php';
business_require_mutation(getDB(), '../views/recurring_bill.php', ['action' => 'billing']);
/* BUSINESS_MUTATION_GUARD_END */
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        header('Content-Type: application/json');
        echo json_encode(['success'=>false,'message'=>'Recurring generation requires POST.']);
        exit;
    }
    if (!verifyCsrf((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success'=>false,'code'=>'csrf_invalid','message'=>'Your form session expired. Reload the page and try again.']);
        exit;
    }
}

// the class of the bills (Chart of Accounts > Class List): required from a browser; from the command line it is
// given as --class=CLASS_ID (without it the bills take the water branch's class)
$classId = null;
try {
    if (PHP_SAPI === 'cli') {
        $cliClass = (int)(getopt('', ['class::'])['class'] ?? 0);
        $classId = $cliClass > 0 ? water_required_class($cliClass, 'the recurring bills') : null;
    } else {
        $classId = water_required_class($_POST['class_id'] ?? null, 'the recurring bills');
    }
} catch (Throwable $e) {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'FAILED: ' . $e->getMessage() . PHP_EOL);
        exit(2);
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'code' => 'class_required', 'message' => $e->getMessage()]);
    exit;
}
$result = WaterPosting::withClass($classId, fn() => rb_process_recurring_bills(
    $pdo,
    date('Y-m-d'),
    (int)($_SESSION['operator_id'] ?? $_SESSION['user_id'] ?? 1)
));

if (PHP_SAPI === 'cli') {
    echo ($result['success'] ? 'SUCCESS: ' : 'FAILED: ') . $result['message'] . PHP_EOL;
    exit($result['success'] ? 0 : 1);
}

header('Content-Type: application/json');
http_response_code($result['success'] ? 200 : 422);
echo json_encode($result);
