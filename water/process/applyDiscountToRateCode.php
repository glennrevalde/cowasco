<?php
// Legacy Rate Code discount endpoint.
// Rate Code discounts were retired when discount behavior moved to General Discounts.
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
business_require_mutation(getDB(), '../views/discounts.php');
/* BUSINESS_MUTATION_GUARD_END */

$_SESSION['flash'] = [
    'msg' => 'Rate Code discounts are no longer used. Configure member privileges in General Discounts.',
    'type' => 'info',
];
header('Location: ../views/discounts.php');
exit;
