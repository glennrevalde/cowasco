<?php
/**
 * Legacy status-only payment endpoint disabled.
 * One-time bills may only be paid through the canonical Collections service so
 * allocations, receipts, balances, audit history, and accounting remain atomic.
 */
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/accessControlHelpers.php';
try {
    $pdo = getDB();
    requireProcessAccess($pdo, 'water_bills_collection');
    $_SESSION['error_msg'] = 'Directly marking a one-time bill as paid is disabled. Record the payment through Collections.';
} catch (Throwable $e) {
    $_SESSION['error_msg'] = 'Access denied.';
}
header('Location: ../views/water_bills_collection.php');
exit;
