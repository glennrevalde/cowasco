<?php
require_once __DIR__ . '/../database/config.php';
require_once '../database/config.php';
/* PROCESS_ACCESS_GUARD_START */
require_once __DIR__ . '/accessControlHelpers.php';
$__processGuardPdo = getDB();
requireProcessAccess($__processGuardPdo);
unset($__processGuardPdo);
/* PROCESS_ACCESS_GUARD_END */
/* BUSINESS_MUTATION_GUARD_START */
require_once __DIR__ . '/../common/businessRequestGuard.php';
business_require_mutation(getDB(), '../views/water_meter.php');
/* BUSINESS_MUTATION_GUARD_END */

require_once '../common/waterRateBillingHelpers.php';

$pdo = getDB();

function wb_global_redirect(): void
{
    header('Location: ../views/water_meter.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    wb_global_redirect();
}

try {
    $result = water_bill_code_ensure(
        $pdo,
        (int)($_SESSION['operator_id'] ?? $_SESSION['user_id'] ?? 0)
    );
    $billCode = $result['bill_code'];

    if (!empty($result['created'])) {
        $_SESSION['flash']['success'] = 'WATER BILL was created and configured automatically.';
    } elseif (!empty($result['updated'])) {
        $_SESSION['flash']['success'] = 'WATER BILL configuration was completed automatically.';
    } else {
        $_SESSION['flash']['success'] = 'WATER BILL is already configured.';
    }
} catch (Throwable $e) {
    $_SESSION['flash']['error'] = $e->getMessage();
}

wb_global_redirect();
