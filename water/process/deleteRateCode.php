<?php
// process/deleteRateCode.php
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

// CSRF check
if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
    $_SESSION['flash'] = ['msg' => 'Invalid CSRF token. Please try again.', 'type' => 'error'];
    header('Location: ../views/rate_codes.php');
    exit;
}
try {
    requireOperationToken('delete_rate_code', (string)($_POST['operation_token'] ?? ''));
} catch (Throwable $e) {
    $_SESSION['flash'] = ['msg' => $e->getMessage(), 'type' => 'error'];
    header('Location: ../views/rate_codes.php');
    exit;
}

$rc_id = (int)($_POST['rc_id'] ?? 0);

if ($rc_id <= 0) {
    $_SESSION['flash'] = ['msg' => 'Invalid rate code ID.', 'type' => 'error'];
    header('Location: ../views/rate_codes.php');
    exit;
}

try {
    $pdo = getDB();
    $pdo->beginTransaction();

    $lock = $pdo->prepare('SELECT rc_code FROM rate_code WHERE rc_id = ? FOR UPDATE');
    $lock->execute([$rc_id]);
    $rateCode = $lock->fetchColumn();
    if ($rateCode === false) {
        throw new RuntimeException('Rate code was not found.');
    }

    $dependencies = [
        ['water_accounts', 'rc_id', 'member'],
        ['water_meter_members', 'rc_id', 'tariff tier'],
    ];
    foreach ($dependencies as [$table, $column, $label]) {
        $check = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column} = ?");
        $check->execute([$rc_id]);
        $count = (int)$check->fetchColumn();
        if ($count > 0) {
            throw new RuntimeException("Cannot delete rate code {$rateCode}: {$count} {$label}(s) still reference it.");
        }
    }

    $stmt = $pdo->prepare("DELETE FROM rate_code WHERE rc_id = :rc_id");
    $stmt->execute([':rc_id' => $rc_id]);
    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('Rate code could not be deleted.');
    }

    $pdo->commit();
    consumeOperationToken('delete_rate_code', (string)$_POST['operation_token']);
    $_SESSION['flash'] = ['msg' => 'Rate code deleted successfully.', 'type' => 'success'];
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[deleteRateCode] ' . $e->getMessage());
    $_SESSION['flash'] = ['msg' => $e->getMessage(), 'type' => 'error'];
}

header('Location: ../views/rate_codes.php');
exit;
