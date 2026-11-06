<?php
/**
 * Legacy route retained for old bookmarks.
 *
 * The maintained Post Billing / SOA workspace lives in views so there is one
 * authoritative UI and one set of posting validations.
 */

require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/accessControlHelpers.php';

$query = '';
$requestedView = strtolower(trim((string)($_GET['view'] ?? '')));
$requestedTab = strtolower(trim((string)($_GET['tab'] ?? '')));
$pdo = getDB();
requireProcessAccess($pdo);

if ($requestedView === 'soa') {
    $query = '?view=soa';
} elseif (in_array($requestedTab, ['soa', 'print'], true)) {
    $query = '?tab=' . rawurlencode($requestedTab);
}

header('Location: ../views/generate_soa.php' . $query);
exit;
