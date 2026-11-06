<?php
/* AUTH_GUARD_START */
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../common/operationTokenHelpers.php';
require_once __DIR__ . '/../process/accessControlHelpers.php';
$__authGuardPdo = getDB();
requirePageAccess($__authGuardPdo, basename(__FILE__, '.php'));
unset($__authGuardPdo);
/* AUTH_GUARD_END */

require_once '../database/config.php';
require_once '../common/discountPrivilegeBillingHelpers.php';
require_once '../common/billingHelpers.php';
require_once '../process/recurringBillingProcess.php';
require_once '../common/superAdminGuard.php';
require_once '../common/displayHelpers.php';
require_once '../process/organizationUnitHelpers.php';

require_once __DIR__ . '/../database/config.php';


if (!function_exists('e')) {
    function e($value) { return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8'); }
}

$current_page = basename($_SERVER['PHP_SELF'], '.php');
$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));
    $userId = (int)($_SESSION['user_id'] ?? 0);
    $catchUpMode = 'schedule_only';
    $runCatchUp = false;

    try {
        $allowedActions = [
            'create_plan', 'enroll_member', 'batch_enroll_members', 'generate_due_bills',
            'soft_delete_bill', 'edit_plan', 'update_plan_status', 'update_subscription_status', 'delete_plan',
        ];
        if (!in_array($action, $allowedActions, true)) {
            throw new InvalidArgumentException('Invalid recurring billing action.');
        }
        if ($userId <= 0) {
            throw new RuntimeException('Your session is missing a valid operator identifier.');
        }
        requireOperationToken('recurring_' . $action, $_POST['operation_token'] ?? null);
        // the actions that post recurring bills ask for the class (Chart of Accounts > Class List): every bill and
        // journal entry of the run carries it
        $postClassId = null;
        if (in_array($action, ['generate_due_bills', 'enroll_member', 'batch_enroll_members'], true)) {
            $postClassId = water_required_class($_POST['class_id'] ?? null, $action === 'generate_due_bills' ? 'the recurring bills' : 'the catch-up bills');
        }

        if ($action === 'generate_due_bills') {
            $result = WaterPosting::withClass($postClassId, fn() => rb_process_recurring_bills($pdo, rb_today(), $userId));
            if (!$result['success']) {
                throw new RuntimeException($result['message']);
            }
            $_SESSION['success_msg'] = $result['message'];
            consumeOperationToken('recurring_' . $action, $_POST['operation_token'] ?? null);
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }

        $pdo->beginTransaction();

        switch ($action) {
            case 'create_plan':
                $firstBillingDate = trim((string)($_POST['first_billing_date'] ?? ''));
                $frequency = trim((string)($_POST['frequency'] ?? 'Monthly'));
                $termDays = filter_var($_POST['term_days'] ?? null, FILTER_VALIDATE_INT);
                if ($termDays === false) {
                    throw new InvalidArgumentException('Term days must be a whole number.');
                }
                $planId = rb_create_plan($pdo, [
                    'bill_code_id' => (int)($_POST['bill_code_id'] ?? 0),
                    'amount' => $_POST['amount'] ?? null,
                    'frequency' => $frequency,
                    'first_billing_date' => $firstBillingDate,
                    'term_days' => $termDays,
                    'created_by' => $userId,
                ]);
                $_SESSION['success_msg'] = 'Recurring plan created.';
                if ($firstBillingDate < rb_today()) {
                    $_SESSION['backdated_plan_notice'] = [
                        'plan_id' => $planId,
                        'first_billing_date' => $firstBillingDate,
                        'frequency' => $frequency,
                        'term_days' => $termDays,
                        'cycles' => rb_billing_cycles_preview($firstBillingDate, $frequency, $termDays, rb_today(), 18),
                    ];
                }
                break;

            case 'enroll_member':
                $catchUpMode = trim((string)($_POST['catch_up_mode'] ?? 'schedule_only'));
                rb_enroll_member(
                    $pdo,
                    (int)($_POST['plan_id'] ?? 0),
                    (int)($_POST['member_id'] ?? 0),
                    trim((string)($_POST['subscription_start_date'] ?? rb_today())),
                    $userId,
                    $catchUpMode
                );
                $runCatchUp = $catchUpMode !== 'schedule_only';
                $_SESSION['success_msg'] = 'Member enrolled in recurring plan.';
                break;

            case 'batch_enroll_members':
                $catchUpMode = trim((string)($_POST['catch_up_mode'] ?? 'schedule_only'));
                $batchResult = rb_batch_enroll_members(
                    $pdo,
                    (int)($_POST['plan_id'] ?? 0),
                    !empty($_POST['enroll_all_members']) ? rb_get_active_member_ids($pdo) : (array)($_POST['member_ids'] ?? []),
                    trim((string)($_POST['subscription_start_date'] ?? rb_today())),
                    $userId,
                    $catchUpMode
                );
                if ($batchResult['created'] <= 0) {
                    throw new RuntimeException($batchResult['errors'][0] ?? 'No subscriptions were created.');
                }
                $runCatchUp = $catchUpMode !== 'schedule_only';
                $_SESSION['success_msg'] = "Batch enrolled {$batchResult['created']} member(s). Skipped {$batchResult['skipped']}.";
                if ($batchResult['errors']) {
                    $_SESSION['error_msg'] = implode(' | ', array_slice($batchResult['errors'], 0, 3));
                }
                break;

            case 'soft_delete_bill':
                if (!wb_is_super_admin()) {
                    throw new RuntimeException('Only a database-designated Super Administrator can delete a recurring bill.');
                }
                rb_soft_delete_bill($pdo, (int)($_POST['bill_id'] ?? 0), $userId, trim((string)($_POST['delete_reason'] ?? 'Deleted from Recurring Billing page')));
                $_SESSION['success_msg'] = 'Recurring bill marked as deleted.';
                break;

            case 'edit_plan':
                rb_update_plan($pdo, [
                    'plan_id' => (int)($_POST['plan_id'] ?? 0),
                    'bill_code_id' => (int)($_POST['bill_code_id'] ?? 0),
                    'amount' => $_POST['amount'] ?? null,
                    'frequency' => trim((string)($_POST['frequency'] ?? 'Monthly')),
                    'first_billing_date' => trim((string)($_POST['first_billing_date'] ?? '')),
                    'term_days' => (int)($_POST['term_days'] ?? 15),
                    'updated_by' => $userId,
                ]);
                $_SESSION['success_msg'] = 'Recurring plan updated. Existing unposted subscription schedules were recalculated without creating bills.';
                break;

            case 'update_plan_status':
                rb_update_plan_status($pdo, (int)($_POST['plan_id'] ?? 0), trim((string)($_POST['status'] ?? '')));
                $_SESSION['success_msg'] = 'Plan status updated.';
                break;

            case 'update_subscription_status':
                rb_update_subscription_status($pdo, (int)($_POST['subscription_id'] ?? 0), trim((string)($_POST['status'] ?? '')));
                $_SESSION['success_msg'] = 'Subscription status updated.';
                break;

            case 'delete_plan':
                rb_delete_plan($pdo, (int)($_POST['plan_id'] ?? 0));
                $_SESSION['success_msg'] = 'Unused recurring plan deleted.';
                break;
        }

        $pdo->commit();
        consumeOperationToken('recurring_' . $action, $_POST['operation_token'] ?? null);

        if ($runCatchUp) {
            $billResult = WaterPosting::withClass($postClassId, fn() => rb_process_recurring_bills($pdo, rb_today(), $userId));
            if ($billResult['success']) {
                $_SESSION['success_msg'] .= " {$billResult['generated']} catch-up bill(s) generated.";
            } else {
                $_SESSION['error_msg'] = 'Enrollment was saved, but catch-up generation was rolled back: ' . $billResult['message'];
            }
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['error_msg'] = $e->getMessage();
    }

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}

// Financial data is generated only by the explicit POST action above or by the scheduled CLI endpoint.

$dataError = null;
try {
    $data = rb_get_data($pdo);
} catch (Throwable $e) {
    error_log('Recurring billing data load failed: ' . $e->getMessage());
    $dataError = 'Recurring billing data could not be loaded. No empty or zero financial result has been assumed.';
    $data = ['members' => [], 'bill_codes' => [], 'plans' => [], 'subscriptions' => [], 'bills' => [], 'stats' => []];
}
$members = $data['members'];
$bill_codes = $data['bill_codes'];
$plans = $data['plans'];
$subscriptions = $data['subscriptions'];
$bills = $data['bills'];
$stats = $data['stats'];
$recurringTokens = [];
foreach (['create_plan', 'enroll_member', 'batch_enroll_members', 'generate_due_bills', 'soft_delete_bill', 'edit_plan', 'update_plan_status', 'update_subscription_status', 'delete_plan'] as $tokenAction) {
    $recurringTokens[$tokenAction] = operationToken('recurring_' . $tokenAction);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../common/fonts.css">
    <title>Recurring Billing</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --white: #ffffff;
            --off-white: #f8f9fb;
            --border: #e8eaed;
            --border-focus: #1a1a2e;
            --text-primary: #0f0f1a;
            --text-secondary: #6b7280;
            --text-muted: #9ca3af;
            --accent: #1a1a2e;
            --brand: #2563eb;
            --success: #059669;
            --success-bg: #ecfdf5;
            --danger: #dc2626;
            --danger-bg: #fef2f2;
            --warning: #d97706;
            --warning-bg: #fffbeb;
            --info-bg: #eff6ff;
            --info-border: #bfdbfe;
            --radius-sm: 8px;
            --radius: 14px;
            --radius-lg: 20px;
            --shadow-sm: 0 1px 3px rgba(0,0,0,.06), 0 1px 2px rgba(0,0,0,.04);
            --shadow-lg: 0 20px 60px rgba(0,0,0,.18);
        }
        body { font-family: 'Sora', sans-serif; background: var(--off-white); color: var(--text-primary); }
        .page { padding: 32px 32px 60px; max-width: 1500px; margin: 0 auto; }
        .header { display:flex; justify-content:space-between; align-items:flex-start; gap:16px; margin-bottom:24px; flex-wrap:wrap; }
        .back { display:inline-flex; align-items:center; gap:6px; color:var(--text-secondary); text-decoration:none; font-size:.8rem; font-weight:700; margin-bottom:6px; }
        .eyebrow { font-size:.7rem; color:var(--text-muted); letter-spacing:.08em; text-transform:uppercase; font-weight:800; }
        .title { font-size:1.55rem; font-weight:700; line-height:1.2; margin-top:2px; }
        .subtitle { font-size:.86rem; color:var(--text-secondary); margin-top:4px; line-height:1.5; }
        .actions { display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
        .btn { display:inline-flex; align-items:center; justify-content:center; gap:6px; padding:0 16px; height:38px; border:none; border-radius:var(--radius-sm); font-family:inherit; font-size:.8rem; font-weight:700; cursor:pointer; text-decoration:none; transition:background .2s, border-color .2s, transform .1s; white-space:nowrap; }
        .btn:active { transform:scale(.98); }
        .btn-primary { background:var(--accent); color:var(--white); }
        .btn-primary:hover { background:#0f0f1a; }
        .btn-brand { background:var(--brand); color:var(--white); }
        .btn-secondary { background:var(--white); color:var(--text-primary); border:1.5px solid var(--border); }
        .btn-secondary:hover { border-color:var(--border-focus); background:var(--off-white); }
        .btn-success { background:var(--success); color:var(--white); }
        .btn-danger { background:var(--danger); color:var(--white); }
        .btn-sm { height:32px; padding:0 11px; font-size:.74rem; }
        .alert { padding:12px 16px; border-radius:var(--radius-sm); margin-bottom:16px; font-size:.875rem; font-weight:700; display:flex; align-items:center; gap:8px; }
        .alert-success { background:var(--success-bg); border:1px solid var(--success); color:var(--success); }
        .alert-danger { background:var(--danger-bg); border:1px solid var(--danger); color:var(--danger); }
        .alert-info { background:var(--info-bg); border:1px solid var(--info-border); color:#075985; }
        .stats { display:grid; grid-template-columns:repeat(5, 1fr); gap:12px; margin-bottom:22px; }
        .stat { background:var(--white); border:1px solid var(--border); border-radius:var(--radius); padding:17px 18px; box-shadow:var(--shadow-sm); }
        .stat-label { color:var(--text-muted); text-transform:uppercase; font-size:.68rem; font-weight:800; letter-spacing:.06em; }
        .stat-value { margin-top:8px; font-size:1.4rem; font-weight:800; line-height:1; }
        .grid { display:grid; grid-template-columns:1fr 1fr; gap:18px; margin-bottom:24px; }
        .card { background:var(--white); border:1px solid var(--border); border-radius:var(--radius); padding:24px; margin-bottom:24px; box-shadow:var(--shadow-sm); }
        .card.no-pad { padding:0; overflow:hidden; }
        .card-header { display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:16px; padding-bottom:12px; border-bottom:1px solid var(--border); }
        .card.no-pad .card-header { margin:0; padding:18px 22px 14px; }
        .card-title { font-size:1.08rem; font-weight:700; }
        .card-sub { font-size:.78rem; color:var(--text-secondary); margin-top:3px; line-height:1.5; }
        .form-grid { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:14px; }
        .form-group { display:flex; flex-direction:column; gap:6px; }
        .full { grid-column:1 / -1; }
        label { font-size:.72rem; font-weight:800; color:var(--text-secondary); text-transform:uppercase; letter-spacing:.05em; }
        input, select, textarea { width:100%; padding:10px 12px; border:1.5px solid var(--border); border-radius:var(--radius-sm); font-family:inherit; font-size:.86rem; outline:none; background:var(--white); transition:border-color .2s; }
        input:focus, select:focus, textarea:focus { border-color:var(--border-focus); }
        textarea { min-height:74px; resize:vertical; }
        select { appearance:none; background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%239ca3af' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 12px center; padding-right:34px; }
        .help { color:var(--text-muted); font-size:.72rem; line-height:1.4; }
        .table-wrap { overflow-x:auto; }
        table { width:100%; border-collapse:collapse; font-size:.84rem; white-space:nowrap; }
        th { text-align:left; padding:12px 16px; color:var(--text-secondary); border-bottom:1.5px solid var(--border); font-weight:800; font-size:.69rem; text-transform:uppercase; letter-spacing:.04em; background:#fcfcfd; }
        td { padding:14px 16px; border-bottom:1px solid var(--border); vertical-align:middle; }
        tr:last-child td { border-bottom:none; }
        tbody tr:hover { background:var(--off-white); }
        .mono { font-family:'JetBrains Mono', monospace; font-size:.8rem; }
        .muted { color:var(--text-muted); font-size:.72rem; margin-top:2px; }
        .badge { display:inline-flex; align-items:center; padding:4px 10px; border-radius:20px; font-size:.68rem; font-weight:800; letter-spacing:.02em; text-transform:uppercase; }
        .badge-active, .badge-paid { background:var(--success-bg); color:var(--success); }
        .badge-unpaid { background:#f3f4f6; color:#374151; }
        .badge-overdue, .badge-danger { background:var(--danger-bg); color:var(--danger); }
        .badge-paused, .badge-inactive { background:var(--warning-bg); color:var(--warning); border:1px solid #fcd34d; }
        .badge-ended, .badge-cancelled { background:#e5e7eb; color:#4b5563; }
        .inline-form { display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
        .inline-form input, .inline-form select { width:auto; min-width:105px; padding:7px 9px; font-size:.78rem; }
        .modal-overlay { position:fixed; inset:0; background:rgba(10,10,20,.55); z-index:1060; display:none; align-items:center; justify-content:center; opacity:0; transition:opacity .25s ease; backdrop-filter:blur(4px); padding:16px; }
        .modal-overlay.active { display:flex; opacity:1; }
        .modal-box { background:var(--white); border-radius:var(--radius-lg); width:100%; max-width:620px; box-shadow:var(--shadow-lg); transform:translateY(18px) scale(.97); opacity:0; transition:transform .25s, opacity .25s; overflow:hidden; }
        .modal-overlay.active .modal-box { transform:translateY(0) scale(1); opacity:1; }
        .modal-header { padding:20px 24px; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; }
        .modal-body { padding:24px; max-height:74vh; overflow-y:auto; }
        .modal-close { background:none; border:none; font-size:1.5rem; cursor:pointer; color:var(--text-muted); }
        @media (max-width:1100px) { .stats { grid-template-columns:repeat(2,1fr); } .grid { grid-template-columns:1fr; } }
        @media (max-width:700px) { .page { padding:20px 16px 60px; } .stats { grid-template-columns:1fr; } .form-grid { grid-template-columns:1fr; } .actions { width:100%; } .actions .btn, .actions form { flex:1; } .actions form .btn { width:100%; } }
    .discount-info{margin:10px 0 16px;padding:12px 14px;background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;border-radius:10px;font-size:.82rem;line-height:1.5;font-weight:700;}
tr.deleted-row td{ text-decoration:line-through; color:#9ca3af; background:#f9fafb; } tr.deleted-row .badge, tr.deleted-row form, tr.deleted-row button{ text-decoration:none; }
.badge-deleted{background:transparent;color:#111827;border-color:transparent;font-weight:700;}
</style>
</head>
<body>
<?php require_once '../common/navbar.php'; ?>

<div class="main-content">
<main class="page">
<div class="discount-info">Discount privileges are now applied automatically when the member is eligible, the Bill Code allows member discount, and a rule exists in Discount Privilege Rules.</div>
    <div class="header">
        <div>
            <a href="billing_management.php" class="back">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" width="14" height="14"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                Back to Billing Hub
            </a>
            <h1 class="title">Recurring Billing</h1>
            <p class="subtitle">Create plans, enroll members, generate recurring bills, and track overdue balances.</p>
        </div>
        <div class="actions">
            <button type="button" class="btn btn-secondary" onclick="openModal('planModal')">Create Plan</button>
            <button type="button" class="btn btn-primary" onclick="openModal('subscriptionModal')">Enroll Member</button>
            <button type="button" class="btn btn-brand" onclick="openModal('generateModal')">Generate Due Bills</button>
        </div>
    </div>

    <?php if (isset($_SESSION['success_msg'])): ?>
        <div class="alert alert-success"><?= e($_SESSION['success_msg']); unset($_SESSION['success_msg']); ?></div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error_msg'])): ?>
        <div class="alert alert-danger"><?= e($_SESSION['error_msg']); unset($_SESSION['error_msg']); ?></div>
    <?php endif; ?>
    <?php if ($dataError !== null): ?>
        <div class="alert alert-danger"><?= e($dataError) ?></div>
    <?php endif; ?>


    <?php if (isset($_SESSION['backdated_plan_notice'])): ?>
        <?php
            $notice = $_SESSION['backdated_plan_notice'];
            unset($_SESSION['backdated_plan_notice']);
        ?>
        <div class="alert alert-warning" style="display:block;">
            <strong>Backdated recurring plan detected.</strong>
            <div style="margin-top:6px;">
                This plan starts on <?= e(rb_format_date($notice['first_billing_date'] ?? null)) ?>, but today is <?= e(rb_format_date(rb_today())) ?>.
                No bills were posted just by creating the plan. When enrolling members, choose a catch-up option.
            </div>

            <?php if (!empty($notice['cycles'])): ?>
                <div style="margin-top:10px; font-size:.82rem;">
                    <strong>Cycle preview:</strong>
                    <?php foreach ($notice['cycles'] as $idx => $cycle): ?>
                        <span class="badge" style="margin:3px 4px 0 0;">
                            <?= e(rb_format_date($cycle['period_start'])) ?> → <?= e(rb_format_date($cycle['period_end'])) ?>
                            <?= !empty($cycle['is_current']) ? 'current' : '' ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="alert alert-info">
        Rule: plans are schedules only. Bills/accounting are created when members are enrolled with a catch-up option or when due recurring bills are generated. Backdated plans can create current-cycle bills or all missed bills only after admin confirmation.
    </div>

    <div class="stats">
        <div class="stat"><div class="stat-label">Generated Bills</div><div class="stat-value"><?= e($stats['generated_bills'] ?? '—') ?></div></div>
        <div class="stat"><div class="stat-label">Active Plans</div><div class="stat-value"><?= e($stats['active_plans'] ?? '—') ?></div></div>
        <div class="stat"><div class="stat-label">Active Subscriptions</div><div class="stat-value"><?= e($stats['active_subscriptions'] ?? '—') ?></div></div>
        <div class="stat"><div class="stat-label">Overdue Bills</div><div class="stat-value"><?= e($stats['overdue_bills'] ?? '—') ?></div></div>
        <div class="stat"><div class="stat-label">Generated Today</div><div class="stat-value"><?= array_key_exists('generated_today', $stats) ? e(rb_money($stats['generated_today'])) : '—' ?></div></div>
    </div>

    <section class="card no-pad">
        <div class="card-header">
            <div>
                <h2 class="card-title">Generated Recurring Bills</h2>
                <div class="card-sub">Journal entries are created only when a recurring bill is generated.</div>
            </div>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Bill</th>
                        <th>Ref</th>
                        <th>Member</th>
                        <th>Bill Code</th>
                        <th>Period</th>
                        <th>Bill Date</th>
                        <th>Due Date</th>
                        <th>Amount</th>
                        <th>Paid</th>
                        <th>Balance</th>
                        <th>Status</th>
                        <th>Payment</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($bills)): ?>
                        <tr><td colspan="12" style="text-align:center; padding:28px; color:var(--text-muted);"><?= $dataError ? 'Recurring bills are unavailable because the database query failed.' : 'No recurring bills generated yet.' ?></td></tr>
                    <?php else: ?>
                        <?php foreach ($bills as $bill): $billStatus = (string)($bill['display_status'] ?? $bill['status'] ?? ''); ?>
                            <tr class="<?= $billStatus === 'deleted' ? 'deleted-row' : '' ?>">
                                <td class="mono">#<?= e($bill['bill_id']) ?></td>
                                <td class="mono" title="<?= e($bill['global_ref_number'] ?: '—') ?>"><?= e(ui_compact_reference($bill['global_ref_number'] ?: '—', 19)) ?></td>
                                <td>
                                    <strong><?= e($bill['member_name']) ?></strong>
                                    <div class="muted">Account #<?= e($bill['accnt_no']) ?></div>
                                </td>
                                <td><strong><?= e($bill['code']) ?></strong></td>
                                <td class="mono"><?= e(rb_format_date($bill['billing_period_start'])) ?> to <?= e(rb_format_date($bill['billing_period_end'])) ?></td>
                                <td class="mono"><?= e(rb_format_date($bill['bill_date'])) ?></td>
                                <td class="mono"><?= e(rb_format_date($bill['due_date'])) ?></td>
                                <td class="mono"><strong><?= e(rb_money($bill['total_amount'])) ?></strong></td>
                                <td class="mono"><?= e(rb_money($bill['amount_paid'])) ?></td>
                                <td class="mono"><?= e(rb_money($bill['balance'])) ?></td>
                                <td><span class="badge badge-<?= e($billStatus) ?> <?= $billStatus === 'deleted' ? 'badge-deleted' : '' ?>"><?= e($billStatus) ?></span></td>
                                <td>
                                    <?php if (!in_array($billStatus, ['paid', 'cancelled', 'deleted'], true)): ?>
                                        <a class="btn btn-success btn-sm" href="water_bills_collection.php?member_id=<?= (int)$bill['member_id'] ?>">
                                            Pay in Collections
                                        </a>
                                    <?php else: ?>
                                        <span class="muted">—</span>
                                    <?php endif; ?>

                                    <?php if (wb_is_super_admin() && $billStatus !== 'deleted'): ?>
                                        <form method="POST" class="inline-form" data-confirm="Mark this recurring bill as deleted? It will not be physically removed." style="margin-top:6px;">
                                            <input type="hidden" name="action" value="soft_delete_bill">
                                            <input type="hidden" name="operation_token" value="<?= e($recurringTokens['soft_delete_bill']) ?>">
                                            <input type="hidden" name="bill_id" value="<?= e($bill['bill_id']) ?>">
                                            <input type="hidden" name="delete_reason" value="Deleted from Recurring Billing table">
                                            <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <div class="grid">
        <section class="card no-pad">
            <div class="card-header">
                <div>
                    <h2 class="card-title">Recurring Plans</h2>
                    <div class="card-sub">Plans control amount, frequency, first billing date, and payment term.</div>
                </div>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Plan</th>
                            <th>Amount</th>
                            <th>Frequency</th>
                            <th>First Billing</th>
                            <th>Term</th>
                            <th>Sample Due</th>
                            <th>Posting</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($plans)): ?>
                            <tr><td colspan="9" style="text-align:center; padding:24px; color:var(--text-muted);">No recurring plans yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($plans as $plan): ?>
                                <tr>
                                    <td>
                                        <strong><?= e($plan['code']) ?></strong>
                                        <div class="muted">Plan #<?= e($plan['plan_id']) ?>, <?= e($plan['type']) ?></div>
                                    </td>
                                    <td class="mono"><strong><?= e(rb_money($plan['amount'])) ?></strong></td>
                                    <td><?= e($plan['frequency']) ?></td>
                                    <td class="mono"><?= e(rb_format_date($plan['first_billing_date'])) ?></td>
                                    <td><?= e($plan['term_days']) ?> days</td>
                                    <td class="mono"><?= e(rb_format_date(rb_due_date(rb_period_end($plan['first_billing_date'], $plan['frequency'], $plan['first_billing_date']), (int)$plan['term_days']))) ?></td>
                                    <td>
                                        <?php if ((int)($plan['bill_count'] ?? 0) > 0): ?>
                                            <span class="badge badge-inactive">Posted / Locked</span>
                                            <div class="muted"><?= e((int)$plan['bill_count']) ?> posted bill(s)</div>
                                        <?php else: ?>
                                            <span class="badge badge-active">Not Posted / Editable</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="badge badge-<?= e($plan['status']) ?>"><?= e($plan['status']) ?></span></td>
                                    <td>
                                        <?php if ((int)($plan['bill_count'] ?? 0) === 0): ?>
                                            <button
                                                type="button"
                                                class="btn btn-secondary btn-sm"
                                                style="margin-bottom:6px;"
                                                data-plan="<?= e(json_encode([
                                                    'plan_id' => (int)$plan['plan_id'],
                                                    'bill_code_id' => (int)$plan['bill_code_id'],
                                                    'amount' => (string)$plan['amount'],
                                                    'frequency' => (string)$plan['frequency'],
                                                    'first_billing_date' => (string)$plan['first_billing_date'],
                                                    'term_days' => (int)$plan['term_days'],
                                                    'code' => (string)$plan['code'],
                                                ], JSON_UNESCAPED_SLASHES)) ?>"
                                                onclick="openEditPlanModal(this)"
                                            >Edit Plan</button>
                                        <?php else: ?>
                                            <div class="muted" style="margin-bottom:6px;">Posted plans cannot be edited.</div>
                                        <?php endif; ?>
                                        <form method="POST" class="inline-form">
                                            <input type="hidden" name="action" value="update_plan_status">
                                            <input type="hidden" name="operation_token" value="<?= e($recurringTokens['update_plan_status']) ?>">
                                            <input type="hidden" name="plan_id" value="<?= e($plan['plan_id']) ?>">
                                            <select name="status" onchange="this.form.submit()">
                                                <option value="active" <?= $plan['status'] === 'active' ? 'selected' : '' ?>>active</option>
                                                <option value="inactive" <?= $plan['status'] === 'inactive' ? 'selected' : '' ?>>inactive</option>
                                            </select>
                                            <noscript><button class="btn btn-secondary btn-sm" type="submit">Save</button></noscript>
                                        </form>
                                        <?php if ((int)($plan['subscription_count'] ?? 0) === 0 && (int)($plan['bill_count'] ?? 0) === 0): ?>
                                            <form method="POST" class="inline-form" data-confirm="Delete this unused recurring plan?" style="margin-top:6px;">
                                                <input type="hidden" name="action" value="delete_plan">
                                                <input type="hidden" name="operation_token" value="<?= e($recurringTokens['delete_plan']) ?>">
                                                <input type="hidden" name="plan_id" value="<?= e($plan['plan_id']) ?>">
                                                <button class="btn btn-danger btn-sm" type="submit">Delete Unused</button>
                                            </form>
                                        <?php else: ?>
                                            <div class="muted">Subscriptions/history exist; use inactive instead of deleting.</div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="card no-pad">
            <div class="card-header">
                <div>
                    <h2 class="card-title">Subscriptions</h2>
                    <div class="card-sub">Next billing date controls the next generated bill.</div>
                </div>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Sub</th>
                            <th>Member</th>
                            <th>Plan</th>
                            <th>Start</th>
                            <th>Last Billed</th>
                            <th>Next Billing</th>
                            <th>Posting</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($subscriptions)): ?>
                            <tr><td colspan="8" style="text-align:center; padding:24px; color:var(--text-muted);">No subscriptions yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($subscriptions as $sub): ?>
                                <tr>
                                    <td class="mono">#<?= e($sub['subscription_id']) ?></td>
                                    <td>
                                        <strong><?= e($sub['member_name']) ?></strong>
                                        <div class="muted">Account #<?= e($sub['accnt_no']) ?></div>
                                    </td>
                                    <td>
                                        <strong><?= e($sub['code']) ?></strong>
                                        <div class="muted"><?= e($sub['frequency']) ?>, <?= e(rb_money($sub['plan_amount'])) ?></div>
                                    </td>
                                    <td class="mono"><?= e(rb_format_date($sub['subscription_start_date'])) ?></td>
                                    <td class="mono"><?= e(rb_format_date($sub['last_billed_date'])) ?></td>
                                    <td class="mono"><strong><?= e(rb_format_date($sub['next_billing_date'])) ?></strong></td>
                                    <td><span class="badge badge-<?= e($sub['status']) ?>"><?= e($sub['status']) ?></span></td>
                                    <td>
                                        <form method="POST" class="inline-form">
                                            <input type="hidden" name="action" value="update_subscription_status">
                                            <input type="hidden" name="operation_token" value="<?= e($recurringTokens['update_subscription_status']) ?>">
                                            <input type="hidden" name="subscription_id" value="<?= e($sub['subscription_id']) ?>">
                                            <select name="status" onchange="this.form.submit()">
                                                <option value="active" <?= $sub['status'] === 'active' ? 'selected' : '' ?>>active</option>
                                                <option value="paused" <?= $sub['status'] === 'paused' ? 'selected' : '' ?>>paused</option>
                                                <option value="ended" <?= $sub['status'] === 'ended' ? 'selected' : '' ?>>ended</option>
                                            </select>
                                            <noscript><button class="btn btn-secondary btn-sm" type="submit">Save</button></noscript>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</main>
</div>

<div class="modal-overlay" id="planModal">
    <div class="modal-box">
        <div class="modal-header">
            <div>
                <h2 class="card-title">Create Recurring Plan</h2>
                <div class="card-sub">Example: Membership Fee, ₱50, Monthly, 05/01, 15 days.</div>
            </div>
            <button class="modal-close" type="button" onclick="closeModal('planModal')">×</button>
        </div>
        <div class="modal-body">
            <form method="POST">
                <input type="hidden" name="action" value="create_plan">
                <input type="hidden" name="operation_token" value="<?= e($recurringTokens['create_plan']) ?>">
                <div class="form-grid">
                    <div class="form-group full">
                        <label>Bill Code</label>
                        <select name="bill_code_id" id="plan_bill_code_id" required onchange="autoFillAmount()">
                            <option value="" disabled selected>Select bill code</option>
                            <?php foreach ($bill_codes as $code): ?>
                                <option value="<?= e($code['code_id']) ?>" data-price="<?= e($code['sales_price']) ?>">
                                    <?= e($code['code']) ?>, <?= e(rb_money($code['sales_price'])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="help">Make sure the selected bill code has AR and Income accounts. The configured Water Billing bill code is reserved and hidden here.</div>
                    </div>
                    <div class="form-group">
                        <label>Amount</label>
                        <input type="number" name="amount" id="plan_amount" step="0.01" min="0.01" value="50.00" required>
                    </div>
                    <div class="form-group">
                        <label>Frequency</label>
                        <select name="frequency" id="plan_frequency" required onchange="updateBackdatedPreview()">
                            <option value="Monthly">Monthly</option>
                            <option value="Quarterly">Quarterly</option>
                            <option value="Semi-Annually">Semi-Annually</option>
                            <option value="Yearly">Yearly</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>First Billing Date</label>
                        <input type="date" name="first_billing_date" id="plan_first_billing_date" value="<?= e(date('Y-m-01')) ?>" required onchange="updateBackdatedPreview()">
                    </div>
                    <div class="form-group">
                        <label>Term in Days</label>
                        <input type="number" name="term_days" id="plan_term_days" value="15" min="1" required oninput="updateBackdatedPreview()">
                    </div>
                    <div class="form-group full">
                        <div id="backdatedPreviewBox" class="alert alert-warning" style="display:none; margin:0;">
                            <strong>Backdated plan preview</strong>
                            <div class="help" style="margin-top:6px;">
                                Creating the plan will not create bills yet. Missing/current bills are created only when members are enrolled with a catch-up option.
                            </div>
                            <div id="backdatedPreviewCycles" style="margin-top:8px;"></div>
                        </div>
                    </div>
                    <div class="form-group full">
                        <button type="submit" class="btn btn-primary">Create Plan</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>


<div class="modal-overlay" id="editPlanModal">
    <div class="modal-box">
        <div class="modal-header">
            <div>
                <h2 class="card-title">Edit Unposted Recurring Plan</h2>
                <div class="card-sub">A plan can be edited only while it has no posted recurring bills.</div>
            </div>
            <button class="modal-close" type="button" onclick="closeModal('editPlanModal')">×</button>
        </div>
        <div class="modal-body">
            <form method="POST">
                <input type="hidden" name="action" value="edit_plan">
                <input type="hidden" name="operation_token" value="<?= e($recurringTokens['edit_plan']) ?>">
                <input type="hidden" name="plan_id" id="edit_plan_id">
                <div class="form-grid">
                    <div class="form-group full">
                        <label>Bill Code</label>
                        <select name="bill_code_id" id="edit_plan_bill_code_id" required onchange="autoFillEditAmount()">
                            <?php foreach ($bill_codes as $code): ?>
                                <option value="<?= e($code['code_id']) ?>" data-price="<?= e($code['sales_price']) ?>">
                                    <?= e($code['code']) ?>, <?= e(rb_money($code['sales_price'])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Amount</label>
                        <input type="number" name="amount" id="edit_plan_amount" step="0.01" min="0.01" required>
                    </div>
                    <div class="form-group">
                        <label>Frequency</label>
                        <select name="frequency" id="edit_plan_frequency" required>
                            <option value="Monthly">Monthly</option>
                            <option value="Quarterly">Quarterly</option>
                            <option value="Semi-Annually">Semi-Annually</option>
                            <option value="Yearly">Yearly</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>First Billing Date</label>
                        <input type="date" name="first_billing_date" id="edit_plan_first_billing_date" required>
                    </div>
                    <div class="form-group">
                        <label>Term in Days</label>
                        <input type="number" name="term_days" id="edit_plan_term_days" min="1" max="3660" required>
                    </div>
                    <div class="form-group full">
                        <div class="alert alert-warning" style="margin:0;">
                            Editing does not post a bill. If members are already enrolled, their next unposted billing date will be moved to the first valid date on the revised schedule without moving it backward.
                        </div>
                    </div>
                    <div class="form-group full">
                        <button type="submit" class="btn btn-primary" data-confirm="Save changes to this unposted recurring plan?">Save Plan Changes</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal-overlay" id="subscriptionModal">
    <div class="modal-box">
        <div class="modal-header">
            <div>
                <h2 class="card-title">Enroll Member</h2>
                <div class="card-sub">The member will be billed on the plan schedule.</div>
            </div>
            <button class="modal-close" type="button" onclick="closeModal('subscriptionModal')">×</button>
        </div>
        <div class="modal-body">
            <form method="POST">
                <input type="hidden" name="action" value="enroll_member">
                <input type="hidden" name="operation_token" value="<?= e($recurringTokens['enroll_member']) ?>">
                <div class="form-grid">
                    <div class="form-group full">
                        <label>Member</label>
                        <select name="member_id" required>
                            <option value="" disabled selected>Select member</option>
                            <?php foreach ($members as $member): ?>
                                <option value="<?= e($member['pkey']) ?>"><?= e($member['pkey']) ?>, <?= e($member['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group full">
                        <label>Plan</label>
                        <select name="plan_id" required>
                            <option value="" disabled selected>Select plan</option>
                            <?php foreach ($plans as $plan): ?>
                                <?php if ($plan['status'] === 'active'): ?>
                                    <option value="<?= e($plan['plan_id']) ?>">
                                        <?= e($plan['code']) ?>, <?= e($plan['frequency']) ?>, <?= e(rb_money($plan['amount'])) ?>, starts <?= e(rb_format_date($plan['first_billing_date'])) ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group full">
                        <label>Enrollment Date</label>
                        <input type="date" name="subscription_start_date" value="<?= e(rb_today()) ?>" required>
                        <div class="help">Used for subscription records. Catch-up billing below controls whether old/current bills are generated.</div>
                    </div>
                    <div class="form-group full">
                        <label>Catch-up Billing Option</label>
                        <select name="catch_up_mode" required>
                            <option value="schedule_only" selected>Schedule only — no immediate bill/accounting</option>
                            <option value="current_cycle">Create current cycle bill only</option>
                            <option value="all_cycles">Create all missed bills from plan start</option>
                        </select>
                        <div class="help">
                            Use "all missed bills" only if the customer must really be billed from the backdated plan start.
                            This creates receivable and journal entries.
                        </div>
                    </div>
                    <div class="form-group full">
                        <?= render_water_class_field(null, true, 'enrollClassId') ?>
                    </div>
                    <div class="form-group full">
                        <button type="submit" class="btn btn-primary">Enroll Member</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>


<div class="modal-overlay" id="batchSubscriptionModal">
    <div class="modal-box">
        <div class="modal-header">
            <div><h2 class="card-title">Batch Enroll Members</h2><div class="card-sub">Enroll many active members into one recurring plan.</div></div>
            <button class="modal-close" type="button" onclick="closeModal('batchSubscriptionModal')">×</button>
        </div>
        <div class="modal-body">
            <form method="POST">
                <input type="hidden" name="action" value="batch_enroll_members">
                <input type="hidden" name="operation_token" value="<?= e($recurringTokens['batch_enroll_members']) ?>">
                <div class="form-grid">
                    <div class="form-group full">
                        <label>Recurring Plan</label>
                        <select name="plan_id" required>
                            <option value="" disabled selected>Select plan</option>
                            <?php foreach ($plans as $plan): if ($plan['status'] !== 'active') continue; ?>
                                <option value="<?= e($plan['plan_id']) ?>"><?= e($plan['code']) ?> — <?= e(rb_money($plan['amount'])) ?> / <?= e($plan['frequency']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group full"><label>Enrollment Date</label><input type="date" name="subscription_start_date" value="<?= e(rb_today()) ?>" required><div class="help">Used for subscription records. Catch-up billing below controls whether old/current bills are generated.</div></div>
                    <div class="form-group full">
                        <label>Catch-up Billing Option</label>
                        <select name="catch_up_mode" required>
                            <option value="schedule_only" selected>Schedule only — no immediate bill/accounting</option>
                            <option value="current_cycle">Create current cycle bill only</option>
                            <option value="all_cycles">Create all missed bills from plan start</option>
                        </select>
                        <div class="help">For batch enrollment, "all missed bills" can create many receivables and journal entries. Use only with admin confirmation.</div>
                    </div>
                    <div class="form-group full">
                        <?= render_water_class_field(null, true, 'batchEnrollClassId') ?>
                    </div>
                    <div class="form-group full">
                        <label class="check-option">
                            <input type="checkbox" name="enroll_all_members" value="1" id="enrollAllRecurringMembers" onchange="toggleRecurringBatchMembers()">
                            <span>
                                Enroll ALL active members
                                <div class="help" style="margin:4px 0 0;color:#1e40af;">Use this for monthly membership fees or other recurring charges that every active member must pay.</div>
                            </span>
                        </label>
                    </div>

                    <div class="form-group full">
                        <label>Members</label>
                        <div id="recurringBatchMembersBox" style="max-height:260px; overflow:auto; border:1px solid var(--border); border-radius:8px; padding:10px; display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:8px;">
                            <?php foreach ($members as $member): ?>
                                <label style="text-transform:none; letter-spacing:0; font-weight:600; color:var(--text-primary);"><input type="checkbox" class="recurring-member-checkbox" name="member_ids[]" value="<?= e($member['pkey']) ?>"> <?= e($member['pkey']) ?> — <?= e($member['full_name']) ?></label>
                            <?php endforeach; ?>
                        </div>
                        <div class="actions" style="margin-top:10px;">
                            <button class="btn btn-secondary btn-sm" type="button" onclick="selectAllRecurringMembers(true)">Select All</button>
                            <button class="btn btn-secondary btn-sm" type="button" onclick="selectAllRecurringMembers(false)">Clear</button>
                        </div>
                    </div>
                </div>
                <div class="actions" style="margin-top:16px;"><button class="btn btn-secondary" type="button" onclick="closeModal('batchSubscriptionModal')">Cancel</button><button class="btn btn-primary" type="submit">Enroll Members</button></div>
            </form>
        </div>
    </div>
</div>

<div class="modal-overlay" id="generateModal">
    <div class="modal-box">
        <div class="modal-header">
            <div>
                <h2 class="card-title">Generate Due Bills</h2>
                <div class="card-sub">Creates the recurring bills due today for every active subscription, with their receivables and journal entries.</div>
            </div>
            <button class="modal-close" type="button" onclick="closeModal('generateModal')">×</button>
        </div>
        <div class="modal-body">
            <form method="POST">
                <input type="hidden" name="action" value="generate_due_bills">
                <input type="hidden" name="operation_token" value="<?= e($recurringTokens['generate_due_bills']) ?>">
                <div class="form-grid">
                    <div class="form-group full">
                        <?= render_water_class_field(null, true, 'generateClassId', 'class_id', 'form-control', 'Class of the bills') ?>
                    </div>
                </div>
                <div class="actions" style="margin-top:16px;"><button class="btn btn-secondary" type="button" onclick="closeModal('generateModal')">Cancel</button><button class="btn btn-brand" type="submit">Generate Due Bills</button></div>
            </form>
        </div>
    </div>
</div>

<div class="modal-overlay" id="deletePlanModal">
    <div class="modal-box" style="max-width:520px;">
        <div class="modal-header"><div><h2 class="card-title">Delete Unused Recurring Plan?</h2><div class="card-sub" id="deletePlanText">Only a plan with no subscriptions or bills can be deleted.</div></div><button class="modal-close" type="button" onclick="closeModal('deletePlanModal')">×</button></div>
        <div class="modal-body">
            <form method="POST">
                <input type="hidden" name="action" value="delete_plan">
                <input type="hidden" name="operation_token" value="<?= e($recurringTokens['delete_plan']) ?>">
                <input type="hidden" name="plan_id" id="delete_plan_id">
                <p class="help" style="margin-bottom:16px;">Plans with transaction history must be set to inactive and are never cascade-deleted.</p>
                <div class="actions"><button type="button" class="btn btn-secondary" onclick="closeModal('deletePlanModal')">Cancel</button><button type="submit" class="btn btn-danger">Yes, Delete Plan</button></div>
            </form>
        </div>
    </div>
</div>

<script>
    function openDeletePlanModal(planId, code) {
        document.getElementById('delete_plan_id').value = planId;
        document.getElementById('deletePlanText').textContent = 'Plan #' + planId + ' — ' + code;
        openModal('deletePlanModal');
    }

    function openEditPlanModal(button) {
        let plan;
        try {
            plan = JSON.parse(button.getAttribute('data-plan') || '{}');
        } catch (error) {
            alert('The recurring plan details could not be loaded.');
            return;
        }
        document.getElementById('edit_plan_id').value = plan.plan_id || '';
        document.getElementById('edit_plan_bill_code_id').value = String(plan.bill_code_id || '');
        document.getElementById('edit_plan_amount').value = Number(plan.amount || 0).toFixed(2);
        document.getElementById('edit_plan_frequency').value = plan.frequency || 'Monthly';
        document.getElementById('edit_plan_first_billing_date').value = plan.first_billing_date || '';
        document.getElementById('edit_plan_term_days').value = plan.term_days || 15;
        openModal('editPlanModal');
    }

    function autoFillEditAmount() {
        const select = document.getElementById('edit_plan_bill_code_id');
        const amount = document.getElementById('edit_plan_amount');
        if (!select || !amount || !select.value) return;
        const option = select.options[select.selectedIndex];
        const price = parseFloat(option.getAttribute('data-price') || '0');
        if (price > 0) amount.value = price.toFixed(2);
    }

    function openModal(id) {
        document.getElementById(id).classList.add('active');
    }

    function closeModal(id) {
        document.getElementById(id).classList.remove('active');
    }


    function selectAllRecurringMembers(checked) {
        document.querySelectorAll('.recurring-member-checkbox').forEach(cb => {
            cb.checked = checked;
        });
    }

    function toggleRecurringBatchMembers() {
        const all = document.getElementById('enrollAllRecurringMembers');
        const box = document.getElementById('recurringBatchMembersBox');

        if (!all || !box) return;

        if (all.checked) {
            box.style.opacity = '.45';
            box.style.pointerEvents = 'none';
            selectAllRecurringMembers(false);
        } else {
            box.style.opacity = '1';
            box.style.pointerEvents = '';
        }
    }

    function autoFillAmount() {
        const select = document.getElementById('plan_bill_code_id');
        const amount = document.getElementById('plan_amount');
        if (!select || !amount || !select.value) return;
        const option = select.options[select.selectedIndex];
        const price = parseFloat(option.getAttribute('data-price') || '0');
        if (price > 0) amount.value = price.toFixed(2);
    }


    function parseLocalDate(value) {
        if (!value) return null;
        const parts = value.split('-').map(Number);
        if (parts.length !== 3) return null;
        return new Date(parts[0], parts[1] - 1, parts[2]);
    }

    function formatLocalDate(date) {
        if (!(date instanceof Date) || isNaN(date.getTime())) return '';
        return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
    }

    function addMonthsLocal(date, months, anchorDate) {
        const targetFirst = new Date(date.getFullYear(), date.getMonth() + months, 1);
        const targetLast = new Date(targetFirst.getFullYear(), targetFirst.getMonth() + 1, 0).getDate();
        const anchorLast = new Date(anchorDate.getFullYear(), anchorDate.getMonth() + 1, 0).getDate();
        const targetDay = anchorDate.getDate() === anchorLast
            ? targetLast
            : Math.min(anchorDate.getDate(), targetLast);
        return new Date(targetFirst.getFullYear(), targetFirst.getMonth(), targetDay);
    }

    function nextCycleDateLocal(date, frequency, anchorDate) {
        let months = 1;
        if (frequency === 'Quarterly') months = 3;
        if (frequency === 'Semi-Annually') months = 6;
        if (frequency === 'Yearly') months = 12;
        return addMonthsLocal(date, months, anchorDate);
    }

    function updateBackdatedPreview() {
        const firstInput = document.getElementById('plan_first_billing_date');
        const freqInput = document.getElementById('plan_frequency');
        const termInput = document.getElementById('plan_term_days');
        const box = document.getElementById('backdatedPreviewBox');
        const target = document.getElementById('backdatedPreviewCycles');

        if (!firstInput || !freqInput || !termInput || !box || !target) return;

        const first = parseLocalDate(firstInput.value);
        const today = new Date();
        today.setHours(0, 0, 0, 0);

        if (!first || first >= today) {
            box.style.display = 'none';
            target.innerHTML = '';
            return;
        }

        const frequency = freqInput.value;
        const cycles = [];
        let cursor = new Date(first.getTime());
        let guard = 0;

        while (cursor <= today && guard < 18) {
            const next = nextCycleDateLocal(cursor, frequency, first);
            const end = new Date(next.getTime());
            end.setDate(end.getDate() - 1);

            cycles.push(`${formatLocalDate(cursor)} → ${formatLocalDate(end)}`);
            cursor = next;
            guard++;
        }

        box.style.display = 'block';
        target.innerHTML = cycles.map((label, idx) => {
            const current = idx === cycles.length - 1 ? ' current' : '';
            return `<span class="badge" style="margin:3px 4px 0 0;">${label}${current}</span>`;
        }).join('');
    }

    document.querySelectorAll('.modal-overlay').forEach(function (overlay) {
        overlay.addEventListener('click', function (event) {
            if (event.target === overlay) overlay.classList.remove('active');
        });
    });

    document.addEventListener('DOMContentLoaded', updateBackdatedPreview);
</script>
</body>
</html>
