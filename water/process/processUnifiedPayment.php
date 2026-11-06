<?php
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/accessControlHelpers.php';
require_once __DIR__ . '/../common/paymentService.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        throw new Exception('Payment processing requires POST.');
    }

    $pdo = getDB();
    requireProcessAccess($pdo, 'water_bills_collection');
/* BUSINESS_MUTATION_GUARD_START */
require_once __DIR__ . '/../common/businessRequestGuard.php';
business_require_mutation(getDB(), '../views/water_bills_collection.php', ['action' => 'collection', 'json' => true]);
/* BUSINESS_MUTATION_GUARD_END */

    $raw = file_get_contents('php://input');
    $input = json_decode($raw ?: '{}', true);
    if (!is_array($input)) {
        throw new Exception('Invalid payment request.');
    }
    if (!verifyCsrf((string)($input['csrf_token'] ?? ''))) throw new Exception('Your form session expired. Refresh the page and try again.');

    $userId = (int)($_SESSION['operator_id'] ?? $_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        http_response_code(401);
        throw new Exception('Your session has expired. Sign in again.');
    }

    $result = ps_process_payment($pdo, $input, $userId);
    echo json_encode(['success' => true] + $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    if (!isset($pdo) || !($pdo instanceof PDO) || !$pdo->inTransaction()) {
        // Nothing to roll back here; the canonical service owns its transaction.
    }
    $status = http_response_code();
    if ($status < 400) http_response_code(422);
    error_log('Unified payment error: ' . $e->getMessage());
    $publicMessage = $e instanceof PDOException
        ? 'Payment could not be completed because the database rejected the transaction. No payment was saved.'
        : $e->getMessage();
    echo json_encode(['success' => false, 'error' => $publicMessage], JSON_UNESCAPED_UNICODE);
}
