<?php
// process/createRateCode.php
require_once __DIR__ . '/../database/config.php';
require_once '../database/config.php';
require_once __DIR__ . '/../common/operationTokenHelpers.php';
/* PROCESS_ACCESS_GUARD_START */
require_once __DIR__ . '/accessControlHelpers.php';
$__processGuardPdo = getDB();
requireProcessAccess($__processGuardPdo);
unset($__processGuardPdo);
/* PROCESS_ACCESS_GUARD_END */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../views/rate_codes.php');
    exit;
}

if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
    $_SESSION['flash'] = ['msg' => 'Invalid CSRF token. Please try again.', 'type' => 'error'];
    header('Location: ../views/rate_codes.php');
    exit;
}

try {
    requireOperationToken('create_rate_code', (string)($_POST['operation_token'] ?? ''));
} catch (Throwable $e) {
    $_SESSION['flash'] = ['msg' => $e->getMessage(), 'type' => 'error'];
    header('Location: ../views/rate_codes.php');
    exit;
}

$rc_code = strtoupper(trim((string)($_POST['rc_code'] ?? '')));
$rc_name = strtoupper(trim((string)($_POST['rc_name'] ?? '')));
// the computation of the rate code's water bills: Straight Rate Billing (the default) or Ladder Rate Billing
require_once __DIR__ . '/../common/waterRateBillingHelpers.php';
$rc_method = (string)($_POST['billing_method'] ?? 'straight');

$errors = [];
if ($rc_code === '' || strlen($rc_code) !== 1 || !ctype_alpha($rc_code)) {
    $errors[] = 'Rate code must be a single letter (A–Z).';
}
if (!isset(water_rate_billing_methods()[$rc_method])) {
    $errors[] = 'Choose Straight Rate Billing or Ladder Rate Billing.';
}
if ($rc_name === '') {
    $errors[] = 'Rate name is required.';
}
if (strlen($rc_name) > 100) {
    $errors[] = 'Rate name must be 100 characters or fewer.';
}

if ($errors) {
    $_SESSION['flash'] = ['msg' => implode(' ', $errors), 'type' => 'error'];
    header('Location: ../views/rate_codes.php');
    exit;
}

try {
    $pdo = getDB();
    $pdo->beginTransaction();

    // Duplicate rate-code letters are intentionally allowed. The internal rc_id
    // identifies the classification, while tariffs are grouped by rc_id + pipe size.
    $stmt = $pdo->prepare('
        INSERT INTO rate_code (rc_code, rc_name, billing_method)
        VALUES (:rc_code, :rc_name, :method)
    ');
    $stmt->execute([
        ':rc_code' => $rc_code,
        ':rc_name' => $rc_name,
        ':method' => $rc_method,
    ]);

    $newId = (int)$pdo->lastInsertId();
    Audit::log('create', 'water', 'rate_code', $newId, $rc_code . ' ' . $rc_name . ': ' . water_rate_billing_methods()[$rc_method], ['page_key' => 'water.rate_codes']);
    $pdo->commit();
    consumeOperationToken('create_rate_code', (string)$_POST['operation_token']);

    $_SESSION['flash'] = [
        'msg' => "Rate code '{$rc_code}' created as Record #{$newId}.",
        'type' => 'success',
    ];
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('[createRateCode] ' . $e->getMessage());
    $message = ((string)$e->getCode() === '23000')
        ? 'The database still enforces unique rate-code letters. Run the duplicate-rate-code SQL update, then try again.'
        : 'A database error occurred. Please try again.';
    $_SESSION['flash'] = ['msg' => $message, 'type' => 'error'];
}

header('Location: ../views/rate_codes.php');
exit;
