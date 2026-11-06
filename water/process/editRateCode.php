<?php
// process/editRateCode.php
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
    requireOperationToken('edit_rate_code', (string)($_POST['operation_token'] ?? ''));
} catch (Throwable $e) {
    $_SESSION['flash'] = ['msg' => $e->getMessage(), 'type' => 'error'];
    header('Location: ../views/rate_codes.php');
    exit;
}

$rc_id = (int)($_POST['rc_id'] ?? 0);
$rc_code = strtoupper(trim((string)($_POST['rc_code'] ?? '')));
$rc_name = strtoupper(trim((string)($_POST['rc_name'] ?? '')));
// the computation of the rate code's water bills: Straight Rate Billing (the default) or Ladder Rate Billing
require_once __DIR__ . '/../common/waterRateBillingHelpers.php';
$rc_method = (string)($_POST['billing_method'] ?? 'straight');

$errors = [];
if ($rc_id <= 0) {
    $errors[] = 'Invalid rate code ID.';
}
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

    $current = $pdo->prepare('SELECT rc_id, billing_method FROM rate_code WHERE rc_id = ? FOR UPDATE');
    $current->execute([$rc_id]);
    $was = $current->fetch(PDO::FETCH_ASSOC);
    if (!$was) {
        throw new InvalidArgumentException('Rate code was not found.');
    }
    $wasMethod = water_rate_billing_method($was['billing_method'] ?? 'straight');

    // Duplicate rate-code letters are intentionally allowed. This update changes
    // only the selected rc_id and never alters another classification with the same code.
    $stmt = $pdo->prepare('
        UPDATE rate_code
        SET rc_code = :rc_code,
            rc_name = :rc_name,
            billing_method = :method
        WHERE rc_id = :rc_id
    ');
    $stmt->execute([
        ':rc_code' => $rc_code,
        ':rc_name' => $rc_name,
        ':method' => $rc_method,
        ':rc_id' => $rc_id,
    ]);
    // a change of computation applies to the bills computed from now on; the bills already posted keep their amounts
    Audit::log('edit', 'water', 'rate_code', $rc_id, $rc_code . ' ' . $rc_name . ($wasMethod !== $rc_method
        ? ': computation changed from ' . water_rate_billing_methods()[$wasMethod] . ' to ' . water_rate_billing_methods()[$rc_method] : ''), ['page_key' => 'water.rate_codes']);

    // the readings saved but not posted keep the amount computed when they were saved (as after a tariff change)
    $pendingNote = '';
    if ($wasMethod !== $rc_method) {
        $pending = $pdo->prepare("SELECT COUNT(*) FROM bill_readings br JOIN water_accounts wa ON wa.pkey = br.member_id
                                   WHERE wa.rc_id = ? AND COALESCE(br.is_billed, 0) = 0 AND COALESCE(br.bill_lifecycle_status, 'active') <> 'deleted'");
        $pending->execute([$rc_id]);
        $n = (int)$pending->fetchColumn();
        $pendingNote = ' Computation: ' . water_rate_billing_methods()[$rc_method] . ' for the bills computed from now on.'
            . ($n > 0 ? " {$n} reading(s) of this rate code saved but not yet posted keep the amount computed when they were saved: save them again on Bill Readings to compute them this way." : '');
    }

    $pdo->commit();
    consumeOperationToken('edit_rate_code', (string)$_POST['operation_token']);
    $_SESSION['flash'] = [
        'msg' => "Rate Code Record #{$rc_id} updated successfully." . $pendingNote,
        'type' => 'success',
    ];
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('[editRateCode] ' . $e->getMessage());
    if ($e instanceof InvalidArgumentException) {
        $message = $e->getMessage();
    } elseif ((string)$e->getCode() === '23000') {
        $message = 'The database still enforces unique rate-code letters. Run the duplicate-rate-code SQL update, then try again.';
    } else {
        $message = 'A database error occurred. Please try again.';
    }
    $_SESSION['flash'] = ['msg' => $message, 'type' => 'error'];
}

header('Location: ../views/rate_codes.php');
exit;
