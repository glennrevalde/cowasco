<?php
/**
 * The loans due of a water-service account's member (v54), for Water Billing > Collections (Cashier): what each of
 * the member's open loans has due as of the date of the transaction (principal, interest, penalty), the figures of
 * Teller > Loan Collection. Display only: the loan payments are not collected on the water cashier page.
 *
 * GET member_id = the water-service account (water_accounts.pkey), date = YYYY-MM-DD (default today)
 */
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/accessControlHelpers.php';
header('Content-Type: application/json; charset=utf-8');
try {
    $pdo = getDB();
    requireProcessAccess($pdo, 'water_bills_collection');
    $accountId = (int)($_GET['member_id'] ?? 0);
    $date = (string)($_GET['date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !checkdate((int)substr($date, 5, 2), (int)substr($date, 8, 2), (int)substr($date, 0, 4))) {
        $date = date('Y-m-d');
    }
    if ($accountId <= 0 || !Coop::ready()) {
        echo json_encode(['members' => [], 'loans' => [], 'totals' => null, 'as_of' => $date]);
        exit;
    }
    $members = LoanTxn::membersOfWaterAccount($accountId);
    echo json_encode(LoanTxn::dueSummary(array_column($members, 'id'), $date), JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('Loan due error: ' . $e->getMessage());
    echo json_encode(['error' => 'Unable to load the loans due.']);
}
