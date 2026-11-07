<?php
/**
 * process/getIndividualSoaData.php
 *
 * The Individual Statement of Account (Generate SOA / Post Billing and Reports > Generate Statement of Account).
 * Read only. The rights of the page it is used on: ?context=generate_soa (Post Billing) or reports_management.
 *
 *   GET action=search     q, kind (all | member | customer), statement_date
 *                         -> {"ok": true, "accounts": [{id, name, kind, member_no, status, accounts, account_number,
 *                             open_count, open_total}]}  one row per member or customer
 *   GET action=invoices   account_id (an account of the member or customer), scope (all | one of its account ids),
 *                         statement_date
 *                         -> {"ok": true, "account": {...}, "accounts": [...], "scope": [...], "invoices": [...], "totals": {...}}
 *   GET action=statement  the same, and items (comma-separated keys "water:12,one_time:7")
 *                         -> {"ok": true, "account", "accounts", "scope", "company", "signatories", "invoices" (the chosen
 *                             ones still unpaid of the accounts), "dropped" (chosen ones that are not), "totals"}
 */
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/accessControlHelpers.php';
require_once __DIR__ . '/../common/businessRequestGuard.php';
$__pdo = getDB();
business_require_read_access($__pdo, ['generate_soa', 'reports_management'], 'generate_soa');
unset($__pdo);

require_once __DIR__ . '/../common/individualSoaHelpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$respond = static function (int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

try {
    $pdo = getDB();
    $action = (string)($_GET['action'] ?? '');

    if ($action === 'search') {
        $kind = (string)($_GET['kind'] ?? 'all');
        if (!in_array($kind, ['all', 'member', 'customer'], true)) {
            $kind = 'all';
        }
        $asOf = trim((string)($_GET['statement_date'] ?? ''));
        $respond(200, ['ok' => true, 'accounts' => isoa_search_accounts($pdo, (string)($_GET['q'] ?? ''), $kind, 15, isoa_valid_date($asOf) ? $asOf : null)]);
    }

    if ($action !== 'invoices' && $action !== 'statement') {
        $respond(400, ['ok' => false, 'error' => 'Unknown request.']);
    }

    $accountId = (int)($_GET['account_id'] ?? 0);
    $account = wa_account_profile($pdo, $accountId);
    if ($account === null) {
        $respond(200, ['ok' => false, 'error' => 'That member or customer could not be found.']);
    }
    $date = trim((string)($_GET['statement_date'] ?? ''));
    if ($date === '') {
        $date = date('Y-m-d');
    }
    if (!isoa_valid_date($date)) {
        $respond(200, ['ok' => false, 'error' => 'The statement date is not a valid date.']);
    }

    // the member's or customer's accounts: all of them, or the one chosen (it must be one of them)
    $accounts = isoa_person_accounts($pdo, $accountId);
    $scope = trim((string)($_GET['scope'] ?? 'all'));
    $scopeAccounts = $accounts;
    if ($scope !== '' && $scope !== 'all') {
        $scopeAccounts = array_values(array_filter($accounts, static fn($a) => (string)$a['id'] === $scope));
        if (!$scopeAccounts) {
            $respond(200, ['ok' => false, 'error' => 'That account is not one of this member\'s or customer\'s accounts.']);
        }
    }
    $open = isoa_open_invoices($pdo, array_column($scopeAccounts, 'id'), $date);

    if ($action === 'invoices') {
        $respond(200, ['ok' => true, 'statement_date' => $date, 'account' => $account, 'accounts' => $accounts, 'scope' => $scopeAccounts,
            'invoices' => $open, 'totals' => isoa_totals($open)]);
    }

    // the statement: only the chosen invoices, as they stand now
    $wanted = array_values(array_unique(array_filter(array_map('trim', explode(',', (string)($_GET['items'] ?? ''))),
        static fn($k) => (bool)preg_match('/^(water|one_time|installment|recurring|sharecap):[1-9]\d{0,9}$/', $k))));
    if (!$wanted) {
        $respond(200, ['ok' => false, 'error' => 'Choose at least one invoice for the statement.']);
    }
    $byKey = [];
    foreach ($open as $i) {
        $byKey[$i['key']] = $i;
    }
    $chosen = [];
    $dropped = [];
    foreach ($wanted as $k) {
        if (isset($byKey[$k])) {
            $chosen[] = $byKey[$k];
        } else {
            $dropped[] = $k;
        }
    }
    usort($chosen, static function (array $a, array $b): int {
        $c = strcmp((string)($a['date'] ?? ''), (string)($b['date'] ?? ''));
        return $c !== 0 ? $c : strcmp($a['reference'], $b['reference']);
    });
    if (!$chosen) {
        $respond(200, ['ok' => false, 'error' => 'None of the chosen invoices is unpaid any more. Load the invoices again.']);
    }

    $respond(200, [
        'ok' => true,
        'statement_date' => $date,
        'account' => $account,
        'accounts' => $accounts,
        'scope' => $scopeAccounts,
        'company' => isoa_company($pdo),
        'signatories' => [
            'general_manager' => institution_officer_for_report($pdo, 'general_manager'),
        ],
        'invoices' => $chosen,
        'dropped' => $dropped,
        'totals' => isoa_totals($chosen),
    ]);
} catch (Throwable $e) {
    error_log('Individual SOA: ' . $e->getMessage());
    $respond(500, ['ok' => false, 'error' => 'The statement could not be prepared. Please try again.']);
}
