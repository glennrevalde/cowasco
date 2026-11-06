<?php
/**
 * Fixed Share Capital Billing: saves the collection settings, adds or edits a member's fixed billing, holds,
 * resumes or deletes it. Posting a bill makes no journal entry (app/ShareCapitalBilling.php).
 */
require_once __DIR__ . '/../database/config.php';
/* PROCESS_ACCESS_GUARD_START */
require_once __DIR__ . '/accessControlHelpers.php';
$__processGuardPdo = getDB();
requireProcessAccess($__processGuardPdo);
unset($__processGuardPdo);
/* PROCESS_ACCESS_GUARD_END */
/* BUSINESS_MUTATION_GUARD_START */
require_once __DIR__ . '/../common/businessRequestGuard.php';
business_require_mutation(getDB(), '../views/share_capital_billing.php', ['action' => 'billing']);
/* BUSINESS_MUTATION_GUARD_END */
require_once __DIR__ . '/../common/operationTokenHelpers.php';

$back = '../views/share_capital_billing.php';
$pageKey = 'water.share_capital_billing';
$action = (string)($_POST['action'] ?? '');
$planId = (int)($_POST['plan_id'] ?? 0);

$done = function (string $type, string $msg) use ($back) {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
    header('Location: ' . $back);
    exit;
};

if (!ShareCapitalBilling::ready()) {
    $done('error', 'Run the database update database/migrations/2027_03_14_share_capital_billing.sql first.');
}

// the right each action takes on the page
$need = match ($action) {
    'save' => $planId > 0 ? 'edit' : 'create',
    'batch' => 'create',
    'settings', 'hold', 'resume' => 'edit',
    'delete' => 'delete',
    default => null,
};
if ($need === null) {
    $done('error', 'Unknown action.');
}
$accessKey = access_resolve_key('share_capital_billing') ?: $pageKey;
if (!Access::can($accessKey, $need)) {
    $done('error', 'Access denied. Your role may not ' . ($need === 'create' ? 'add' : $need) . ' fixed share capital billings.');
}

try {
    requireOperationToken('share_capital_billing', (string)($_POST['operation_token'] ?? ''));
} catch (Throwable $e) {
    $done('error', $e->getMessage());
}

try {
    switch ($action) {
        case 'settings':
            $classId = (int)($_POST['class_id'] ?? 0);
            ShareCapitalBilling::saveSettings(trim((string)($_POST['txn_code'] ?? '')), $classId > 0 ? $classId : null, $pageKey);
            consumeOperationToken('share_capital_billing', (string)$_POST['operation_token']);
            $code = ShareCapitalBilling::codes()[ShareCapitalBilling::settings()['code']] ?? null;
            $done('success', 'Saved. The collections post by ' . ($code ? $code['code'] . ' ' . $code['description'] : 'the code chosen') . '.');
            break;

        case 'save':
            $f = [
                'member_id' => (int)($_POST['member_id'] ?? 0),
                'sav_account_id' => (int)($_POST['sav_account_id'] ?? 0),
                'frequency' => (string)($_POST['frequency'] ?? ''),
                'start_date' => (string)($_POST['start_date'] ?? ''),
                'posting_day' => (int)($_POST['posting_day'] ?? 0),
                'amount' => str_replace(',', '', trim((string)($_POST['amount'] ?? ''))),
                'term_days' => trim((string)($_POST['term_days'] ?? '')),
            ];
            if ($planId > 0) {
                $old = ShareCapitalBilling::plan($planId);
                if (!$old || $old['status'] === 'deleted') {
                    fail('That fixed billing was deleted.');
                }
                // the member of a billing is not changed (a billing is deleted and added again for another member)
                $f['member_id'] = (int)$old['member_id'];
            }
            $before = $planId > 0 ? (int)DB::value('SELECT COUNT(*) FROM share_capital_bills WHERE plan_id = :p', ['p' => $planId]) : 0;
            $id = ShareCapitalBilling::save($f, $planId > 0 ? $planId : null, $pageKey);
            consumeOperationToken('share_capital_billing', (string)$_POST['operation_token']);
            $p = ShareCapitalBilling::plan($id);
            $posted = (int)DB::value('SELECT COUNT(*) FROM share_capital_bills WHERE plan_id = :p', ['p' => $id]) - $before;
            $member = Coop::member((int)$p['member_id']);
            $msg = ($planId > 0 ? 'Saved the fixed billing of ' : 'Added ') . ($member['full_name'] ?? '') . ($planId > 0 ? '.' : ' to fixed billing.')
                . ($p['status'] === 'active' && $p['next_bill_date'] ? ' Next posting: ' . date('m/d/Y', strtotime($p['next_bill_date'])) . '.' : '')
                . ($posted > 0 ? ' ' . $posted . ' bill(s) of posting days already past were posted.' : '')
                . (!ShareCapitalBilling::waterAccountsOf((int)$p['member_id']) ? ' The member has no water-service account: open one so the cashier can collect the bills.' : '');
            $done('success', $msg);
            break;

        case 'batch':
            // Batch Share Capital Billing: the accounts ticked, the same amount, frequency, start date, posting day
            // and term for each (every one or none)
            $ids = json_decode((string)($_POST['accounts_json'] ?? ''), true);
            $ids = is_array($ids) ? array_values(array_unique(array_filter(array_map('intval', $ids)))) : [];
            $common = [
                'amount' => str_replace(',', '', trim((string)($_POST['amount'] ?? ''))), 'frequency' => (string)($_POST['frequency'] ?? ''),
                'start_date' => (string)($_POST['start_date'] ?? ''), 'posting_day' => (string)($_POST['posting_day'] ?? ''),
                'term_days' => trim((string)($_POST['term_days'] ?? '')),
            ];
            $rows = array_map(fn($id, $i) => ['sav_account_id' => $id, '_line' => $i + 1], $ids, array_keys($ids));
            $r = ShareCapitalBilling::saveBatch($rows, $common, 'batch', $pageKey);
            consumeOperationToken('share_capital_billing', (string)$_POST['operation_token']);
            $done('success', count($r['ids']) . ' member(s) put on fixed billing (batch ' . $r['batch_no'] . '), ₱' . number_format((float)$r['amount'], 2) . ' a posting.'
                . ($r['bills'] > 0 ? ' ' . $r['bills'] . ' bill(s) of posting days already past were posted.' : ''));
            break;

        case 'hold':
            ShareCapitalBilling::hold($planId, trim((string)($_POST['reason'] ?? '')), $pageKey);
            consumeOperationToken('share_capital_billing', (string)$_POST['operation_token']);
            $done('success', 'The billing is on hold: no bill is posted until it is resumed.');
            break;

        case 'resume':
            $next = ShareCapitalBilling::resume($planId, $pageKey);
            consumeOperationToken('share_capital_billing', (string)$_POST['operation_token']);
            $p = ShareCapitalBilling::plan($planId);
            $done('success', 'The billing is resumed. Next posting: ' . date('m/d/Y', strtotime((string)($p['next_bill_date'] ?: $next))) . '.');
            break;

        case 'delete':
            $n = ShareCapitalBilling::delete($planId, trim((string)($_POST['reason'] ?? '')), $pageKey);
            consumeOperationToken('share_capital_billing', (string)$_POST['operation_token']);
            $done('success', 'Deleted the fixed billing.' . ($n ? ' ' . $n . ' unpaid bill(s) were cancelled.' : ''));
            break;
    }
} catch (UserError $e) {
    $done('error', $e->getMessage());
} catch (Throwable $e) {
    error_log('[saveShareCapitalBilling] ' . $e->getMessage());
    $done('error', 'The fixed billing could not be saved: ' . $e->getMessage());
}
