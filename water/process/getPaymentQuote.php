<?php
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/accessControlHelpers.php';
require_once __DIR__ . '/../common/paymentService.php';

header('Content-Type: application/json; charset=utf-8');
try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        throw new Exception('Payment quotation requires POST.');
    }
    $pdo = getDB();
    requireProcessAccess($pdo, 'water_bills_collection');
    $input = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($input)) throw new Exception('Invalid quote request.');
    if (!verifyCsrf((string)($input['csrf_token'] ?? ''))) throw new Exception('Your form session expired. Refresh the page and try again.');
    $quote = ps_build_quote(
        $pdo,
        (int)($input['member_id'] ?? 0),
        is_array($input['items'] ?? null) ? $input['items'] : [],
        (string)($input['transaction_date'] ?? date('Y-m-d')),
        false,
        $input
    );
    $quote['principal_amount'] = ps_cents_to_money($quote['principal_cents']);
    $quote['penalty_amount'] = ps_cents_to_money($quote['penalty_cents']);
    $quote['compromise_amount'] = ps_cents_to_money($quote['compromise_cents']);
    $quote['total_amount'] = ps_cents_to_money($quote['total_cents']);
    $quote['allocations'] = array_map(static function (array $a): array {
        return [
            'db_type' => $a['type'],
            'db_id' => $a['source_id'],
            'description' => $a['description'],
            'issue_date' => $a['issue_date'],
            'due_date' => $a['due_date'],
            'balance_before' => ps_cents_to_money($a['balance_before_cents']),
            'principal_amount' => ps_cents_to_money($a['principal_cents']),
            'manual_charge_type' => (string)($a['manual_charge_type'] ?? 'none'),
            'manual_charge_amount' => ps_cents_to_money($a['penalty_cents']),
            'penalty_amount' => ps_cents_to_money(
                in_array($a['manual_charge_type'] ?? 'none', ['penalty', 'compromise', 'both'], true) ? $a['penalty_cents'] : 0
            ),
            'balance_after' => ps_cents_to_money($a['balance_after_cents']),
            'allocation_status' => $a['allocation_status'],
        ];
    }, $quote['allocations']);
    echo json_encode(['success' => true, 'quote' => $quote], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    $status = http_response_code();
    if ($status < 400) http_response_code(422);
    error_log('Payment quote error: ' . $e->getMessage());
    $publicMessage = $e instanceof PDOException
        ? 'The payment quotation could not be calculated because of a database error.'
        : $e->getMessage();
    echo json_encode(['success' => false, 'error' => $publicMessage], JSON_UNESCAPED_UNICODE);
}
