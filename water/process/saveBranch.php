<?php
require_once __DIR__ . '/../database/config.php';
require_once '../database/config.php';
/* PROCESS_ACCESS_GUARD_START */
require_once __DIR__ . '/accessControlHelpers.php';
$__processGuardPdo = getDB();
requireProcessAccess($__processGuardPdo);
unset($__processGuardPdo);
/* PROCESS_ACCESS_GUARD_END */

$_SESSION['flash']['info'] = 'This legacy Organization Unit form has been retired. Use the canonical Organization Units page.';
header('Location: ../views/organization_units.php#branchesSection');
exit;
