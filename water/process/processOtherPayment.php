<?php
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/accessControlHelpers.php';
header('Content-Type: application/json; charset=utf-8');
try {
    $pdo = getDB();
    requireProcessAccess($pdo, 'water_bills_collection');
    http_response_code(410);
    echo json_encode([
        'success' => false,
        'error' => 'This legacy payment endpoint is disabled. Use the unified Collection page so balances, allocations, receipts, and accounting are processed atomically.'
    ]);
} catch (Throwable $e) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied.']);
}
