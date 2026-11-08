<?php
/* AUTH_GUARD_START */
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../common/operationTokenHelpers.php';
require_once __DIR__ . '/../process/accessControlHelpers.php';
$__authGuardPdo = getDB();
requirePageAccess($__authGuardPdo, basename(__FILE__, '.php'));
unset($__authGuardPdo);
/* AUTH_GUARD_END */

/**
 * Fixed Share Capital Billing: the members' fixed share capital contributions, posted on their day (no journal entry)
 * and collected by the water cashier (a deposit to the share capital account by the savings transaction code chosen
 * here). See app/ShareCapitalBilling.php.
 */
if (!function_exists('e')) {
    function e($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
}

$pageKey = 'water.share_capital_billing';
$ready = ShareCapitalBilling::ready();
$canCreate = Access::can($pageKey, 'create');
$canEdit = Access::can($pageKey, 'edit');
$canDelete = Access::can($pageKey, 'delete');
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$postedNow = 0;
$plans = $members = $codes = [];
$settings = ['code' => 'CSD', 'class_id' => null];
$classes = [];
$loadError = '';
if ($ready) {
    try {
        // the bills whose posting day has come are posted as the page opens (as at the cashier, and by the scheduler)
        $postedNow = ShareCapitalBilling::postDue();
        $plans = ShareCapitalBilling::plans();
        $members = ShareCapitalBilling::eligibleMembers();
        $codes = ShareCapitalBilling::codes();
        $settings = ShareCapitalBilling::settings();
        $classes = Classes::ready() ? Classes::options($settings['class_id']) : [];
    } catch (Throwable $e) {
        error_log('[share_capital_billing] ' . $e->getMessage());
        $loadError = 'The fixed billing could not be loaded: ' . $e->getMessage();
    }
}
$token = operationToken('share_capital_billing');
$freq = ShareCapitalBilling::FREQUENCIES;

$activeN = count(array_filter($plans, fn($p) => $p['status'] === 'active'));
$holdN = count(array_filter($plans, fn($p) => $p['status'] === 'on_hold'));
$unpaidN = array_sum(array_map(fn($p) => (int)$p['bills_unpaid'], $plans));
$unpaidAmt = array_sum(array_map(fn($p) => (float)$p['unpaid_amount'], $plans));
$collectedAmt = array_sum(array_map(fn($p) => (float)$p['paid_amount'], $plans));
$code = $codes[$settings['code']] ?? null;

// the members and their accounts for the form (only members with an open share capital account)
$memberJs = [];
foreach ($members as $m) {
    $memberJs[] = [
        'id' => (int)$m['id'], 'name' => $m['full_name'], 'no' => $m['member_no'],
        'water' => array_map(fn($w) => $w['account_number'] ?: (string)$w['pkey'], $m['water_accounts']),
        'accounts' => array_map(fn($a) => ['id' => (int)$a['id'], 'no' => $a['account_no'], 'product' => $a['product_name'], 'balance' => number_format((float)$a['balance'], 2)], $m['accounts']),
    ];
}
$planJs = [];
foreach ($plans as $p) {
    $planJs[(int)$p['plan_id']] = [
        'id' => (int)$p['plan_id'], 'member_id' => (int)$p['member_id'], 'account_id' => (int)$p['sav_account_id'], 'frequency' => $p['frequency'],
        'start' => $p['start_date'], 'day' => (int)$p['posting_day'], 'amount' => number_format((float)$p['amount'], 2, '.', ''),
        'name' => $p['full_name'], 'account_no' => $p['account_no'],
        'last' => (string)($p['last_bill_date'] ?? ''), 'next' => (string)($p['next_bill_date'] ?? ''), 'status' => $p['status'],
        'term' => $p['term_days'] === null ? '' : (string)(int)$p['term_days'],
    ];
}
$billsOf = [];
$allBills = $plans ? ShareCapitalBilling::billsOfPlans(array_column($plans, 'plan_id'), 36) : [];
foreach ($plans as $p) {
    $billsOf[(int)$p['plan_id']] = array_map(fn($b) => [
        'date' => $b['bill_date'], 'due' => (string)($b['due_date'] ?? ''), 'amount' => number_format((float)$b['amount'], 2), 'paid' => number_format((float)$b['amount_paid'], 2),
        'status' => $b['status'], 'receipts' => (string)($b['receipts'] ?? ''),
    ], $allBills[(int)$p['plan_id']] ?? []);
}
// Batch Share Capital Billing: every open share capital account not yet on fixed billing, with its member
$onBilling = array_flip(array_map(fn($p) => (int)$p['sav_account_id'], $plans));
$batchJs = [];
foreach ($members as $m) {
    foreach ($m['accounts'] as $a) {
        if (!isset($onBilling[(int)$a['id']])) {
            $batchJs[] = ['id' => (int)$a['id'], 'name' => $m['full_name'], 'no' => $m['member_no'], 'acct' => $a['account_no'], 'product' => $a['product_name'],
                'water' => implode(', ', array_map(fn($w) => $w['account_number'] ?: (string)$w['pkey'], $m['water_accounts']))];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="../common/fonts.css">
<title>Fixed Share Capital Billing</title>
<style>
:root {
    --white:#ffffff; --surface:#f5f7fa; --surface-2:#eef1f6; --border:#e2e6ed; --border-2:#c9d0dc;
    --text-1:#111827; --text-2:#4b5563; --text-3:#8a94a6; --brand:#1e3a5f; --brand-mid:#2d5282; --brand-light:#ebf2ff;
    --green:#047857; --green-bg:#ecfdf5; --green-border:#a7f3d0; --red:#dc2626; --red-bg:#fef2f2; --red-border:#fca5a5;
    --amber:#b45309; --amber-bg:#fffbeb; --amber-border:#fcd34d;
    --radius-sm:10px; --radius:14px;
    --shadow-sm:0 1px 3px rgba(15,23,42,.08), 0 1px 2px rgba(15,23,42,.04);
    --shadow-md:0 10px 24px -12px rgba(15,23,42,.28), 0 2px 6px rgba(15,23,42,.06);
    --font:'Plus Jakarta Sans', 'Sora', sans-serif; --mono:'JetBrains Mono', monospace;
}
*, *::before, *::after { box-sizing:border-box; }
body { margin:0; font-family:var(--font); background:var(--surface); color:var(--text-1); -webkit-font-smoothing:antialiased; }
.page { max-width:1380px; margin:0 auto; padding:26px 28px 72px; }
.back-link { display:inline-flex; align-items:center; gap:6px; color:var(--text-2); font-size:.8rem; font-weight:600; text-decoration:none; margin-bottom:10px; }
.back-link:hover { color:var(--brand); }
.page-header { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; flex-wrap:wrap; margin-bottom:20px; }
.page-title { font-size:1.6rem; font-weight:800; letter-spacing:-.02em; margin:0; }
.page-sub { font-size:.86rem; color:var(--text-2); margin:6px 0 0; max-width:820px; line-height:1.55; }
.btn { display:inline-flex; align-items:center; justify-content:center; gap:7px; height:38px; padding:0 18px; border:none; border-radius:var(--radius-sm);
    font-family:var(--font); font-size:.82rem; font-weight:700; cursor:pointer; white-space:nowrap; text-decoration:none; transition:background .18s, box-shadow .18s, transform .1s; }
.btn:active { transform:translateY(1px); }
.btn svg { width:15px; height:15px; }
.btn-primary { background:var(--brand); color:#fff; box-shadow:0 2px 0 #142a46, var(--shadow-sm); }
.btn-primary:hover { background:var(--brand-mid); }
.btn-ghost { background:var(--white); color:var(--text-1); border:1.5px solid var(--border); box-shadow:0 1px 0 rgba(15,23,42,.06); }
.btn-ghost:hover { border-color:var(--brand); color:var(--brand); background:var(--brand-light); }
.btn-danger { background:var(--red); color:#fff; box-shadow:0 2px 0 #991b1b; }
.btn-sm { height:30px; padding:0 10px; font-size:.74rem; }
.alert { padding:12px 16px; border-radius:var(--radius-sm); font-size:.86rem; font-weight:600; margin-bottom:18px; line-height:1.5; }
.alert-success { background:var(--green-bg); border:1px solid var(--green-border); color:var(--green); }
.alert-error { background:var(--red-bg); border:1px solid var(--red-border); color:var(--red); }
.alert-note { background:var(--brand-light); border:1px solid #c7d7f5; color:#1e3a8a; }

.tiles { display:grid; grid-template-columns:repeat(4, minmax(0,1fr)); gap:14px; margin-bottom:20px; }
.tile { background:linear-gradient(180deg, #fff, #fbfcfe); border:1px solid var(--border); border-radius:var(--radius); padding:16px 18px; box-shadow:var(--shadow-md); }
.tile-label { font-size:.74rem; color:var(--text-2); font-weight:600; }
.tile-value { font-size:1.35rem; font-weight:800; margin-top:6px; font-variant-numeric:tabular-nums; }
.tile-note { font-size:.72rem; color:var(--text-3); margin-top:3px; }

.card { background:var(--white); border:1px solid var(--border); border-radius:var(--radius); box-shadow:var(--shadow-md); margin-bottom:22px; }
.card-head { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; padding:16px 20px; border-bottom:1px solid var(--border); }
.card-title { font-size:1rem; font-weight:800; margin:0; }
.card-sub { font-size:.78rem; color:var(--text-2); margin:3px 0 0; }
.card-body { padding:18px 20px; }

.settings-grid { display:grid; grid-template-columns:minmax(0,1.3fr) minmax(0,1fr) auto; gap:14px; align-items:end; }
.field-label { display:block; font-size:.72rem; font-weight:700; color:var(--text-2); margin-bottom:6px; }
.field { width:100%; height:40px; padding:0 12px; border:1.5px solid var(--border); border-radius:var(--radius-sm); background:#fff; font-family:var(--font); font-size:.86rem; color:var(--text-1); outline:none; }
.field:focus { border-color:var(--brand-mid); box-shadow:0 0 0 3px rgba(45,82,130,.12); }
textarea.field { height:auto; min-height:78px; padding:10px 12px; resize:vertical; }
.entry-line { margin-top:12px; font-size:.78rem; color:var(--text-2); line-height:1.55; }
.entry-line strong { color:var(--text-1); }

.toolbar { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
.search { height:38px; width:260px; max-width:100%; padding:0 12px; border:1.5px solid var(--border); border-radius:var(--radius-sm); font-family:var(--font); font-size:.84rem; }
.table-wrap { overflow-x:auto; }
table.plans { width:100%; border-collapse:collapse; font-size:.83rem; }
.plans-scroll { overflow-x:auto; max-width:100%; }
table.plans th { overflow-wrap:normal; word-break:normal; text-align:left; font-size:.7rem; color:var(--text-2); font-weight:700; padding:11px 10px; background:var(--surface); border-bottom:1px solid var(--border); vertical-align:bottom; }
table.plans td { white-space:nowrap; padding:12px 10px; border-bottom:1px solid var(--border); vertical-align:top; }
table.plans tr:last-child td { border-bottom:0; }
table.plans td.num, table.plans th.num { text-align:right; font-variant-numeric:tabular-nums; }
table.plans th.nowrap { white-space:nowrap; }
.who { font-weight:700; }
.muted { color:var(--text-3); font-size:.74rem; margin-top:2px; }
.mono { font-family:var(--mono); font-size:.8rem; }
.state { font-weight:700; font-size:.78rem; }
.state.active { color:var(--green); }
.state.on_hold { color:var(--amber); }
.warn { color:var(--amber); font-size:.74rem; margin-top:3px; font-weight:600; }
.row-actions { display:grid; grid-template-columns:repeat(2, auto); gap:6px; justify-content:end; }
.row-actions form { display:contents; }
table.plans td.who-cell { white-space:normal; min-width:170px; }
table.plans .v { display:block; }
table.plans .v .muted, table.plans .v .warn { display:block; }
table.plans .v .hold-why { white-space:normal; max-width:160px; }
table.plans td.act { width:1%; }
body.wsh-body .main-content label.field-label, .modal label.field-label { font-size:.76rem; }
.sr-only { position:absolute; width:1px; height:1px; overflow:hidden; clip:rect(0 0 0 0); white-space:nowrap; }
.empty { padding:34px 20px; text-align:center; color:var(--text-2); font-size:.88rem; }

.overlay { position:fixed; inset:0; background:rgba(15,23,42,.5); display:none; align-items:flex-start; justify-content:center; padding:5vh 14px; z-index:1200; overflow:auto; }
.overlay.open { display:flex; }
.modal { width:min(640px, 100%); background:#fff; border-radius:16px; box-shadow:0 30px 70px -20px rgba(15,23,42,.45); }
.modal.wide { width:min(820px, 100%); }
.modal-head { display:flex; align-items:center; justify-content:space-between; padding:16px 20px; border-bottom:1px solid var(--border); }
.modal-title { font-size:1.02rem; font-weight:800; margin:0; }
.modal-close { width:34px; height:34px; border-radius:9px; border:1.5px solid var(--border); background:#fff; cursor:pointer; font-size:1.1rem; color:var(--text-2); }
.modal-body { padding:18px 20px; }
.modal-foot { display:flex; justify-content:flex-end; gap:10px; padding:14px 20px; border-top:1px solid var(--border); background:var(--surface); border-radius:0 0 16px 16px; }
.form-grid { display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); gap:14px; }
.form-grid .full { grid-column:1 / -1; }
.preview { margin-top:14px; padding:11px 13px; border-radius:var(--radius-sm); background:var(--surface); border:1px solid var(--border); font-size:.8rem; color:var(--text-2); line-height:1.55; }
.preview strong { color:var(--text-1); }
table.bills { width:100%; border-collapse:collapse; font-size:.82rem; }
table.bills th, table.bills td { padding:8px 10px; border-bottom:1px solid var(--border); text-align:left; }
table.bills th { font-size:.7rem; color:var(--text-2); }
table.bills td.num, table.bills th.num { text-align:right; }

.head-actions { display:flex; gap:10px; flex-wrap:wrap; }
.term-hint { align-self:end; font-size:.74rem; color:var(--text-2); line-height:1.5; padding-bottom:4px; }

/* Batch Share Capital Billing */
.modal.batch { width:min(1120px, 100%); }
.batch-grid { display:grid; grid-template-columns:minmax(260px, 330px) minmax(0, 1fr); gap:0; }
.batch-side { padding:18px 20px; border-right:1px solid var(--border); display:flex; flex-direction:column; gap:12px; background:linear-gradient(180deg, #fbfcfe, #fff); }
.batch-main { padding:16px 18px; min-width:0; display:flex; flex-direction:column; gap:12px; }
.batch-tools { display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
.batch-tools .search { flex:1; min-width:180px; width:auto; }
.check-line { display:inline-flex; align-items:center; gap:7px; font-size:.8rem; color:var(--text-2); font-weight:600; cursor:pointer; }
.batch-list { border:1px solid var(--border); border-radius:var(--radius-sm); max-height:min(56vh, 520px); overflow:auto; background:#fff; }
table.pick { width:100%; border-collapse:collapse; font-size:.82rem; }
table.pick th { position:sticky; top:0; z-index:1; background:var(--surface); text-align:left; font-size:.7rem; color:var(--text-2); font-weight:700; padding:10px 12px; border-bottom:1px solid var(--border); }
table.pick td { padding:10px 12px; border-bottom:1px solid var(--border); vertical-align:top; }
table.pick tr:last-child td { border-bottom:0; }
table.pick tr.on td { background:#f4f8ff; }
table.pick td.ck, table.pick th.ck { width:38px; }
table.pick input[type=checkbox], .check-line input { width:17px; height:17px; accent-color:var(--brand); }
.batch-count { font-size:.82rem; color:var(--text-2); }
.batch-count strong { color:var(--text-1); }
.modal-foot.split { justify-content:space-between; align-items:center; flex-wrap:wrap; }
.modal-foot.split .btns { display:flex; gap:10px; }
.check-line.phone-only { display:none; }

@media (max-width: 980px) {
    .tiles { grid-template-columns:repeat(2, minmax(0,1fr)); }
    .settings-grid { grid-template-columns:1fr; }
}
@media (max-width: 760px) {
    .page { padding:18px 14px 60px; }
    .page-title { font-size:1.3rem; }
    .page-header .btn { width:100%; }
    .search { width:100%; }
    .toolbar { width:100%; }
    table.plans thead { display:none; }
    table.plans, table.plans tbody, table.plans tr, table.plans td { display:block; width:100%; }
    table.plans tr { padding:12px 14px; border-bottom:1px solid var(--border); }
    table.plans td { border:0; padding:4px 0; display:flex; justify-content:space-between; gap:12px; text-align:right; white-space:normal; }
    table.plans .v .muted { display:inline; margin-left:6px; }
    table.plans td::before { content:attr(data-label); font-size:.72rem; color:var(--text-2); font-weight:700; text-align:left; flex-shrink:0; }
    table.plans td.num { text-align:right; }
    table.plans td.first { display:block; text-align:left; }
    table.plans td.first::before { content:none; }
    .row-actions { display:flex; flex-wrap:wrap; justify-content:flex-start; margin-top:6px; }
    table.plans td.who-cell { max-width:none; }
    table.plans td.act { width:100%; }
    table.plans td.act::before { content:none; }
    .form-grid { grid-template-columns:1fr; }
    .modal-foot .btn { flex:1; }
    .head-actions { width:100%; }
    .head-actions .btn { flex:1; }
    .batch-grid { grid-template-columns:1fr; }
    .batch-side { border-right:0; border-bottom:1px solid var(--border); }
    .batch-list { max-height:none; }
    table.pick thead { display:none; }
    table.pick, table.pick tbody, table.pick tr, table.pick td { display:block; width:100%; }
    table.pick tr { display:grid; grid-template-columns:34px minmax(0,1fr); padding:10px 12px; border-bottom:1px solid var(--border); }
    table.pick td { border:0; padding:2px 0; }
    table.pick td.ck { grid-row:span 3; width:auto; }
    .modal-foot.split .btns { width:100%; }
    .check-line.phone-only { display:inline-flex; }
    /* the count, Exit and Save stay in view while the list scrolls */
    .modal.batch .modal-foot { position:sticky; bottom:0; z-index:3; box-shadow:0 -10px 20px -14px rgba(15,23,42,.4); }
}
@media (max-width: 480px) { .tiles { grid-template-columns:1fr; } }
</style>
</head>
<body>
<?php require_once '../common/navbar.php'; ?>
<div class="main-content">
<main class="page">
    <a class="back-link" href="billing_management.php">&larr; Back to Billing Management</a>
    <div class="page-header">
        <div>
            <h1 class="page-title">Fixed Share Capital Billing</h1>
            <p class="page-sub">The fixed share capital contribution of each member, posted on its day and collected by the water cashier. Posting a bill makes no journal entry: it tracks the contribution still unpaid. The books move only on collection, by the savings transaction code below.</p>
        </div>
        <?php $canUpload = userCanAccessPage(getDB(), 'bill_balance_upload'); ?>
        <?php if ($ready && ($canCreate || $canUpload)): ?>
            <div class="head-actions">
                <?php if ($canUpload): ?><a class="btn btn-ghost" href="bill_balance_upload.php" title="Upload the unpaid balances of the recurring, installment, one-time and fixed share capital bills from a file"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" width="15" height="15"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>Upload Balances</a><?php endif; ?>
                <?php if ($canCreate): ?>
                <a class="btn btn-ghost" href="share_capital_import.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>Import</a>
                <button type="button" class="btn btn-ghost" onclick="openBatch()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>Batch billing</button>
                <button type="button" class="btn btn-primary" onclick="openPlan(0)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>Add member</button>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($flash): ?><div class="alert <?= ($flash['type'] ?? '') === 'success' ? 'alert-success' : 'alert-error' ?>"><?= e($flash['msg'] ?? '') ?></div><?php endif; ?>
    <?php if (!$ready): ?>
        <div class="alert alert-error">Run the database update database/migrations/2027_03_14_share_capital_billing.sql first.</div>
    <?php elseif ($loadError !== ''): ?>
        <div class="alert alert-error"><?= e($loadError) ?></div>
    <?php else: ?>
        <?php if ($postedNow > 0): ?><div class="alert alert-note"><?= (int)$postedNow ?> bill(s) whose posting day has come were posted just now.</div><?php endif; ?>

        <div class="tiles">
            <div class="tile"><div class="tile-label">Members on fixed billing</div><div class="tile-value"><?= $activeN ?></div><div class="tile-note"><?= $holdN ?> on hold</div></div>
            <div class="tile"><div class="tile-label">Unpaid contributions</div><div class="tile-value"><?= number_format($unpaidN) ?></div><div class="tile-note">bills posted, not yet collected</div></div>
            <div class="tile"><div class="tile-label">Unpaid amount</div><div class="tile-value">&#8369;<?= number_format($unpaidAmt, 2) ?></div><div class="tile-note">not on the books until collected</div></div>
            <div class="tile"><div class="tile-label">Collected on these bills</div><div class="tile-value">&#8369;<?= number_format($collectedAmt, 2) ?></div><div class="tile-note">deposited to share capital</div></div>
        </div>

        <section class="card" aria-labelledby="settingsTitle">
            <div class="card-head"><div><h2 class="card-title" id="settingsTitle">Collection</h2><p class="card-sub">How a contribution is posted when the cashier collects it.</p></div></div>
            <div class="card-body">
                <form method="post" action="../process/saveShareCapitalBilling.php">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="operation_token" value="<?= e($token) ?>">
                    <input type="hidden" name="action" value="settings">
                    <div class="settings-grid">
                        <div>
                            <label class="field-label" for="scCode">Transaction code upon collection</label>
                            <select class="field" id="scCode" name="txn_code" <?= $canEdit ? '' : 'disabled' ?>>
                                <?php foreach ($codes as $c): ?>
                                    <option value="<?= e($c['code']) ?>" <?= $c['code'] === $settings['code'] ? 'selected' : '' ?>><?= e($c['code'] . ' ' . $c['description']) ?></option>
                                <?php endforeach; ?>
                                <?php if (!$codes): ?><option value="">No deposit code set up</option><?php endif; ?>
                            </select>
                        </div>
                        <div>
                            <label class="field-label" for="scClass">Class</label>
                            <select class="field" id="scClass" name="class_id" <?= $canEdit ? '' : 'disabled' ?>>
                                <option value="">The class chosen at the cashier</option>
                                <?php foreach ($classes as $cid => $cname): ?>
                                    <option value="<?= (int)$cid ?>" <?= (int)$cid === (int)$settings['class_id'] ? 'selected' : '' ?>><?= e($cname) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php if ($canEdit): ?><div><button type="submit" class="btn btn-primary">Save</button></div><?php endif; ?>
                    </div>
                </form>
                <p class="entry-line">On collection: <strong>Dr <?= $code && $code['opposite_kind'] === 'gl' && $code['opposite_account_id'] ? e((string)DB::value("SELECT CONCAT(code, ' ', name) FROM accounts WHERE id = :a", ['a' => $code['opposite_account_id']])) : 'the cashier\'s cash account' ?></strong>, <strong>Cr the member's share capital account</strong>, a row of the Savings Account Transaction numbered in the savings series, ready for the passbook. The receipt shows it as <?= e(ShareCapitalBilling::CODE_NAME) ?> beside the bills paid.</p>
            </div>
        </section>

        <section class="card" aria-labelledby="plansTitle">
            <div class="card-head">
                <div><h2 class="card-title" id="plansTitle">Members on fixed billing</h2><p class="card-sub">Only members with an open share capital account. The cashier collects a member's bills on the member's water-service account.</p></div>
                <div class="toolbar"><input type="search" class="search" id="planFind" placeholder="Find a member or account" oninput="filterPlans()"></div>
            </div>
            <div class="plans-scroll">
                <table class="plans" id="plansTable">
                    <thead><tr><th>Member name</th><th>Share capital account</th><th>Frequency</th><th>Start date</th><th class="num nowrap">Posting day</th><th class="num">Amount</th><th class="num">Unpaid</th><th>Status</th><th><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody>
                    <?php foreach ($plans as $p): $pid = (int)$p['plan_id']; ?>
                        <tr data-find="<?= e(mb_strtolower($p['full_name'] . ' ' . $p['member_no'] . ' ' . $p['account_no'])) ?>">
                            <td class="first who-cell" data-label="Member">
                                <div class="who"><?= e($p['full_name']) ?></div>
                                <div class="muted">Member no. <?= e($p['member_no']) ?><?= $p['water_accounts'] ? ', water account ' . e(implode(', ', array_map(fn($w) => $w['account_number'] ?: $w['pkey'], $p['water_accounts']))) : '' ?></div>
                                <?php if (!$p['water_accounts']): ?><div class="warn">No water-service account yet: open one so the cashier can collect these bills.</div><?php endif; ?>
                            </td>
                            <td data-label="Share capital account"><span class="v"><span class="mono"><?= e($p['account_no']) ?></span><?= $p['account_status'] !== 'active' ? '<span class="muted">' . e(ucfirst($p['account_status'])) . '</span>' : '' ?></span></td>
                            <td data-label="Frequency"><span class="v"><?= e($freq[$p['frequency']][0] ?? $p['frequency']) ?><span class="muted"><?= $p['term_days'] === null ? 'No due date' : 'Term ' . (int)$p['term_days'] . ' day' . ((int)$p['term_days'] === 1 ? '' : 's') ?></span></span></td>
                            <td data-label="Start date"><?= e(date('m/d/Y', strtotime($p['start_date']))) ?></td>
                            <td class="num" data-label="Posting day"><?= (int)$p['posting_day'] ?></td>
                            <td class="num" data-label="Amount">&#8369;<?= number_format((float)$p['amount'], 2) ?></td>
                            <td class="num" data-label="Unpaid"><span class="v">&#8369;<?= number_format((float)$p['unpaid_amount'], 2) ?><span class="muted"><?= (int)$p['bills_unpaid'] ?> bill<?= (int)$p['bills_unpaid'] === 1 ? '' : 's' ?></span></span></td>
                            <td data-label="Status"><span class="v"><span class="state <?= e($p['status']) ?>"><?= e(ShareCapitalBilling::STATUSES[$p['status']] ?? $p['status']) ?></span>
                                <?php if ($p['status'] === 'active' && $p['next_bill_date']): ?><span class="muted">Next <?= e(date('m/d/Y', strtotime($p['next_bill_date']))) ?></span><?php endif; ?>
                                <?php if ($p['status'] === 'on_hold' && $p['hold_reason']): ?><span class="muted hold-why"><?= e($p['hold_reason']) ?></span><?php endif; ?>
                                <?php if (!in_array($p['account_status'], ['active', 'dormant'], true) || $p['member_status'] !== 'active'): ?><span class="warn hold-why">Not billed: <?= !in_array($p['account_status'], ['active', 'dormant'], true) ? 'the account is ' . e($p['account_status']) : 'the member is ' . e($p['member_status']) ?></span><?php endif; ?></span>
                            </td>
                            <td class="act" data-label="">
                                <div class="row-actions">
                                    <button type="button" class="btn btn-ghost btn-sm" onclick="openBills(<?= $pid ?>)">Bills</button>
                                    <?php if ($canEdit): ?>
                                        <button type="button" class="btn btn-ghost btn-sm" onclick="openPlan(<?= $pid ?>)">Edit</button>
                                        <?php if ($p['status'] === 'active'): ?>
                                            <button type="button" class="btn btn-ghost btn-sm" onclick="openHold(<?= $pid ?>)">Hold</button>
                                        <?php else: ?>
                                            <form method="post" action="../process/saveShareCapitalBilling.php" onsubmit="return confirm('Resume the billing of <?= e(addslashes($p['full_name'])) ?>? The next bill is posted on the next posting day; the days on hold are not billed.')">
                                                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="operation_token" value="<?= e($token) ?>">
                                                <input type="hidden" name="action" value="resume"><input type="hidden" name="plan_id" value="<?= $pid ?>">
                                                <button type="submit" class="btn btn-ghost btn-sm">Resume</button>
                                            </form>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if ($canDelete): ?><button type="button" class="btn btn-ghost btn-sm" style="color:var(--red)" onclick="openDelete(<?= $pid ?>)">Delete</button><?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="empty" id="plansEmpty" <?= $plans ? 'hidden' : '' ?>><?= $plans ? 'No member matches.' : 'No member is on fixed billing yet.' . ($canCreate ? ' Add a member with Add member.' : '') ?></div>
            </div>
        </section>
    <?php endif; ?>
</main>
</div>

<?php if ($ready && $loadError === ''): ?>
<div class="overlay" id="planModal" onclick="if (event.target === this) closeModal('planModal')">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="planTitle">
        <form method="post" action="../process/saveShareCapitalBilling.php" onsubmit="return checkPlan()">
            <div class="modal-head"><h2 class="modal-title" id="planTitle">Add member to fixed billing</h2><button type="button" class="modal-close" onclick="closeModal('planModal')" aria-label="Close">&times;</button></div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="operation_token" value="<?= e($token) ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="plan_id" id="fPlan" value="">
                <div class="form-grid">
                    <div class="full">
                        <label class="field-label" for="fMember">Member name</label>
                        <select class="field" name="member_id" id="fMember" required onchange="fillAccounts()">
                            <option value="">Choose a member with share capital</option>
                            <?php foreach ($members as $m): ?><option value="<?= (int)$m['id'] ?>"><?= e($m['full_name'] . ' (' . $m['member_no'] . ')') ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="full">
                        <label class="field-label" for="fAccount">Share capital account</label>
                        <select class="field" name="sav_account_id" id="fAccount" required onchange="updatePreview()"></select>
                    </div>
                    <div>
                        <label class="field-label" for="fFreq">Frequency</label>
                        <select class="field" name="frequency" id="fFreq" required onchange="updatePreview()">
                            <?php foreach ($freq as $k => [$label]): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="field-label" for="fAmount">Amount</label>
                        <input class="field mono" type="number" name="amount" id="fAmount" min="0.01" step="0.01" required oninput="updatePreview()">
                    </div>
                    <div>
                        <label class="field-label" for="fStart">Start date</label>
                        <input class="field" type="date" name="start_date" id="fStart" required onchange="updatePreview()">
                    </div>
                    <div>
                        <label class="field-label" for="fDay">Posting day</label>
                        <select class="field" name="posting_day" id="fDay" required onchange="updatePreview()">
                            <?php for ($d = 1; $d <= 31; $d++): ?><option value="<?= $d ?>"><?= $d ?></option><?php endfor; ?>
                        </select>
                    </div>
                    <div>
                        <label class="field-label" for="fTerm">Term (days)</label>
                        <input class="field mono" type="number" name="term_days" id="fTerm" min="0" max="3650" step="1" placeholder="Blank: no due date" oninput="updatePreview()">
                    </div>
                    <div class="term-hint">The due date of each bill is its posting date plus the term. Left blank, the bill has no due date (open).</div>
                </div>
                <div class="preview" id="fPreview">Choose the member, the frequency, the start date and the posting day.</div>
            </div>
            <div class="modal-foot"><button type="button" class="btn btn-ghost" onclick="closeModal('planModal')">Exit</button><button type="submit" class="btn btn-primary">Save</button></div>
        </form>
    </div>
</div>

<div class="overlay" id="holdModal" onclick="if (event.target === this) closeModal('holdModal')">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="holdTitle">
        <form method="post" action="../process/saveShareCapitalBilling.php">
            <div class="modal-head"><h2 class="modal-title" id="holdTitle">Stop the billing for now</h2><button type="button" class="modal-close" onclick="closeModal('holdModal')" aria-label="Close">&times;</button></div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="operation_token" value="<?= e($token) ?>">
                <input type="hidden" name="action" value="hold"><input type="hidden" name="plan_id" id="hPlan">
                <p style="margin:0 0 12px;font-size:.86rem;color:var(--text-2);line-height:1.55" id="hText"></p>
                <label class="field-label" for="hReason">Reason</label>
                <textarea class="field" name="reason" id="hReason" maxlength="255" placeholder="Why the billing stops (optional)"></textarea>
            </div>
            <div class="modal-foot"><button type="button" class="btn btn-ghost" onclick="closeModal('holdModal')">Exit</button><button type="submit" class="btn btn-primary">Hold billing</button></div>
        </form>
    </div>
</div>

<div class="overlay" id="deleteModal" onclick="if (event.target === this) closeModal('deleteModal')">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="delTitle">
        <form method="post" action="../process/saveShareCapitalBilling.php">
            <div class="modal-head"><h2 class="modal-title" id="delTitle">Delete the fixed billing</h2><button type="button" class="modal-close" onclick="closeModal('deleteModal')" aria-label="Close">&times;</button></div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="operation_token" value="<?= e($token) ?>">
                <input type="hidden" name="action" value="delete"><input type="hidden" name="plan_id" id="dPlan">
                <p style="margin:0 0 12px;font-size:.86rem;color:var(--text-2);line-height:1.55" id="dText"></p>
                <label class="field-label" for="dReason">Reason</label>
                <textarea class="field" name="reason" id="dReason" maxlength="255" required placeholder="Why the member leaves fixed billing"></textarea>
            </div>
            <div class="modal-foot"><button type="button" class="btn btn-ghost" onclick="closeModal('deleteModal')">Exit</button><button type="submit" class="btn btn-danger">Delete</button></div>
        </form>
    </div>
</div>

<div class="overlay" id="batchModal" onclick="if (event.target === this) closeModal('batchModal')">
    <div class="modal batch" role="dialog" aria-modal="true" aria-labelledby="batchTitle">
        <form method="post" action="../process/saveShareCapitalBilling.php" onsubmit="return checkBatch()">
            <div class="modal-head"><h2 class="modal-title" id="batchTitle">Batch Share Capital Billing</h2><button type="button" class="modal-close" onclick="closeModal('batchModal')" aria-label="Close">&times;</button></div>
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="operation_token" value="<?= e($token) ?>">
            <input type="hidden" name="action" value="batch">
            <input type="hidden" name="accounts_json" id="bAccounts" value="">
            <div class="batch-grid">
                <div class="batch-side">
                    <div><label class="field-label" for="bAmount">Amount</label><input class="field mono" type="number" name="amount" id="bAmount" min="0.01" step="0.01" required oninput="batchPreview()"></div>
                    <div><label class="field-label" for="bFreq">Frequency</label>
                        <select class="field" name="frequency" id="bFreq" required onchange="batchPreview()"><?php foreach ($freq as $k => [$label]): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?></select></div>
                    <div><label class="field-label" for="bStart">Start date</label><input class="field" type="date" name="start_date" id="bStart" required onchange="batchPreview()"></div>
                    <div><label class="field-label" for="bDay">Posting day</label>
                        <select class="field" name="posting_day" id="bDay" required onchange="batchPreview()"><?php for ($d = 1; $d <= 31; $d++): ?><option value="<?= $d ?>"><?= $d ?></option><?php endfor; ?></select></div>
                    <div><label class="field-label" for="bTerm">Term (days)</label><input class="field mono" type="number" name="term_days" id="bTerm" min="0" max="3650" step="1" placeholder="Blank: no due date" oninput="batchPreview()">
                        <div class="term-hint" style="padding-top:6px">Blank: the bills have no due date (open).</div></div>
                    <div class="preview" id="bPreview" style="margin-top:0">Type the amount and choose the frequency, the start date and the posting day.</div>
                </div>
                <div class="batch-main">
                    <div class="batch-tools">
                        <input type="search" class="search" id="bFind" placeholder="Find a member or account" oninput="renderBatch()">
                        <label class="check-line"><input type="checkbox" id="bWaterOnly" onchange="renderBatch()"> With a water-service account only</label>
                        <label class="check-line phone-only"><input type="checkbox" id="bAllPhone" onchange="tickShown(this.checked)"> Tick every member shown</label>
                    </div>
                    <div class="batch-list">
                        <table class="pick">
                            <thead><tr><th class="ck"><input type="checkbox" id="bAll" aria-label="Tick every member shown" onchange="tickShown(this.checked)"></th><th>Member name</th><th>Share capital account</th><th>Water-service account</th></tr></thead>
                            <tbody id="bBody"></tbody>
                        </table>
                        <div class="empty" id="bEmpty" hidden></div>
                    </div>
                </div>
            </div>
            <div class="modal-foot split">
                <div class="batch-count" id="bCount">Selected: <strong>0</strong> members</div>
                <div class="btns"><button type="button" class="btn btn-ghost" onclick="closeModal('batchModal')">Exit</button><button type="submit" class="btn btn-primary">Save</button></div>
            </div>
        </form>
    </div>
</div>

<div class="overlay" id="billsModal" onclick="if (event.target === this) closeModal('billsModal')">
    <div class="modal wide" role="dialog" aria-modal="true" aria-labelledby="billsTitle">
        <div class="modal-head"><h2 class="modal-title" id="billsTitle">Bills</h2><button type="button" class="modal-close" onclick="closeModal('billsModal')" aria-label="Close">&times;</button></div>
        <div class="modal-body"><div class="table-wrap"><table class="bills"><thead><tr><th>Posting date</th><th>Due date</th><th class="num">Amount</th><th class="num">Collected</th><th>Status</th><th>Receipt</th></tr></thead><tbody id="billsBody"></tbody></table></div></div>
        <div class="modal-foot"><button type="button" class="btn btn-ghost" onclick="closeModal('billsModal')">Exit</button></div>
    </div>
</div>

<script>
const SC_MEMBERS = <?= json_encode($memberJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const SC_PLANS = <?= json_encode((object)$planJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const SC_BILLS = <?= json_encode((object)$billsOf, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const SC_FREQ = <?= json_encode(array_map(fn($f) => ['label' => $f[0], 'months' => $f[1]], $freq)) ?>;
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const fmtDate = iso => { const [y, m, d] = String(iso).split('-'); return `${m}/${d}/${y}`; };

function closeModal(id) { document.getElementById(id).classList.remove('open'); }
function openModal(id) { document.getElementById(id).classList.add('open'); }

// a member's open share capital accounts; the plan being edited keeps its own account in the list
function fillAccounts(keepId) {
    const m = SC_MEMBERS.find(x => String(x.id) === document.getElementById('fMember').value);
    const sel = document.getElementById('fAccount');
    const accounts = m ? m.accounts.slice() : [];
    sel.innerHTML = accounts.length
        ? accounts.map(a => `<option value="${a.id}">${esc(a.no)} ${esc(a.product)} (balance ${esc(a.balance)})</option>`).join('')
        : '<option value="">No open share capital account</option>';
    if (keepId && accounts.some(a => a.id === keepId)) sel.value = String(keepId);
    updatePreview();
}

// the first posting date: the posting day on or after the start date (the last day of a shorter month)
function dayIn(y, m, day) { const last = new Date(y, m + 1, 0).getDate(); return new Date(y, m, Math.min(day, last)); }
function addDays(d, n) { const x = new Date(d.getFullYear(), d.getMonth(), d.getDate()); x.setDate(x.getDate() + n); return x; }
function iso(d) { return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; }
function firstDate(start, day) {
    const [y, m] = start.split('-').map(Number);
    let d = dayIn(y, m - 1, day);
    if (iso(d) < start) d = dayIn(y, m, day);
    return d;
}
function updatePreview() {
    const start = document.getElementById('fStart').value;
    const day = Number(document.getElementById('fDay').value || 0);
    const f = SC_FREQ[document.getElementById('fFreq').value];
    const amt = Number(document.getElementById('fAmount').value || 0);
    const m = SC_MEMBERS.find(x => String(x.id) === document.getElementById('fMember').value);
    const box = document.getElementById('fPreview');
    if (!start || !day || !f) { box.textContent = 'Choose the member, the frequency, the start date and the posting day.'; return; }
    const first = firstDate(start, day);
    const step = d => dayIn(d.getFullYear(), d.getMonth() + f.months, day);
    // an edit: the new schedule starts after the month of the last bill posted (a month is never billed twice);
    // the bills already posted stay as they are
    const plan = SC_PLANS[document.getElementById('fPlan').value] || null;
    const freqKey = document.getElementById('fFreq').value;
    const unchanged = plan && plan.frequency === freqKey && plan.start === start && plan.day === day && String(plan.account_id) === document.getElementById('fAccount').value;
    let d0 = first;
    if (plan && plan.last) {
        const [ly, lm] = plan.last.split('-').map(Number);
        const after = iso(new Date(ly, lm, 0));
        while (iso(d0) <= after) d0 = step(d0);
    }
    // the new schedule starts after the period the bills posted cover (from the month the old schedule would bill next);
    // on hold, from today (as on resuming)
    if (plan && plan.status === 'on_hold') { const t = new Date(new Date().toDateString()); while (d0 < t) d0 = step(d0); }
    else if (plan && plan.last && plan.next) { const [ny, nm] = plan.next.split('-').map(Number); const from = new Date(ny, nm - 1, 1); while (d0 < from) d0 = step(d0); }
    if (unchanged && plan.next) { const [ny, nm, nd] = plan.next.split('-').map(Number); d0 = new Date(ny, nm - 1, nd); }
    const dates = [d0];
    for (let k = 1; k < 3; k++) dates.push(step(dates[k - 1]));
    const every = f.label.toLowerCase().replace('semi-annually', 'six months').replace('monthly', 'month').replace('quarterly', 'three months').replace('annually', 'year');
    const amount = `&#8369;${amt > 0 ? amt.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) : '0.00'}`;
    const termRaw = document.getElementById('fTerm').value.trim();
    const termLine = termRaw === '' ? ' No due date: the bills are open.' : ` Each due ${Number(termRaw)} day${Number(termRaw) === 1 ? '' : 's'} after its posting date (the first: <strong>${fmtDate(iso(addDays(d0, Number(termRaw))))}</strong>).`;
    box.innerHTML = (plan
            ? `Next bills of <strong>${amount}</strong>: <strong>${dates.map(d => fmtDate(iso(d))).join(', ')}</strong> and every ${esc(every)} afterwards.`
              + (plan.last ? ` The last bill posted is of ${fmtDate(plan.last)}; the bills already posted keep their amount.` : '')
            : `Bills of <strong>${amount}</strong> posted on <strong>${dates.map(d => fmtDate(iso(d))).join(', ')}</strong> and every ${esc(every)} afterwards.`)
        + termLine
        + (m && !m.water.length ? '<br><span style="color:var(--amber);font-weight:700">This member has no water-service account: open one on Water Service Accounts so the cashier can collect the bills.</span>' : '')
        + (!plan || !plan.last ? (d0 < new Date(new Date().toDateString()) ? '<br>The posting days already past are posted at once, as the contribution expected since the start date.' : '') : '');
}
function openPlan(id) {
    const p = id ? SC_PLANS[id] : null;
    document.getElementById('planTitle').textContent = p ? 'Edit fixed billing: ' + p.name : 'Add member to fixed billing';
    document.getElementById('fPlan').value = p ? p.id : '';
    const member = document.getElementById('fMember');
    member.value = p ? String(p.member_id) : '';
    member.disabled = !!p;
    if (p && !member.value) {
        // a member no longer listed (no open account): kept as it is
        member.insertAdjacentHTML('beforeend', `<option value="${p.member_id}">${esc(p.name)}</option>`);
        member.value = String(p.member_id);
    }
    member.dispatchEvent(new Event('change'));
    fillAccounts(p ? p.account_id : 0);
    document.getElementById('fFreq').value = p ? p.frequency : 'monthly';
    document.getElementById('fStart').value = p ? p.start : iso(new Date());
    document.getElementById('fDay').value = p ? String(p.day) : String(new Date().getDate());
    document.getElementById('fAmount').value = p ? p.amount : '';
    document.getElementById('fTerm').value = p ? p.term : '';
    updatePreview();
    openModal('planModal');
}
function checkPlan() {
    // the member select is locked on an edit: its value goes with the form all the same
    const member = document.getElementById('fMember');
    if (member.disabled) { member.disabled = false; }
    if (!document.getElementById('fAccount').value) { alert('Choose the share capital account.'); return false; }
    return true;
}
function openHold(id) {
    const p = SC_PLANS[id];
    document.getElementById('hPlan').value = id;
    document.getElementById('hText').textContent = `No bill is posted for ${p.name} (${p.account_no}) until the billing is resumed. The bills already posted stay collectible.`;
    document.getElementById('hReason').value = '';
    openModal('holdModal');
}
function openDelete(id) {
    const p = SC_PLANS[id];
    document.getElementById('dPlan').value = id;
    document.getElementById('dText').textContent = `${p.name} (${p.account_no}) leaves fixed billing. Its unpaid bills are cancelled; what was collected stays on the share capital account.`;
    document.getElementById('dReason').value = '';
    openModal('deleteModal');
}
function openBills(id) {
    const p = SC_PLANS[id];
    const rows = SC_BILLS[id] || [];
    document.getElementById('billsTitle').textContent = `Bills: ${p.name} (${p.account_no})`;
    const label = {unpaid: 'Unpaid', partial: 'Partly paid', paid: 'Paid', cancelled: 'Cancelled'};
    document.getElementById('billsBody').innerHTML = rows.length
        ? rows.map(b => `<tr><td>${fmtDate(b.date)}</td><td>${b.due ? fmtDate(b.due) : '<span style="color:var(--text-3)">Open</span>'}</td><td class="num">&#8369;${esc(b.amount)}</td><td class="num">&#8369;${esc(b.paid)}</td><td>${esc(label[b.status] || b.status)}</td><td class="mono">${esc(b.receipts || '-')}</td></tr>`).join('')
        : '<tr><td colspan="6" style="color:var(--text-2)">No bill posted yet: the first is posted on its posting day.</td></tr>';
    openModal('billsModal');
}
function filterPlans() {
    const q = document.getElementById('planFind').value.trim().toLowerCase();
    let shown = 0;
    document.querySelectorAll('#plansTable tbody tr').forEach(tr => { const ok = !q || tr.dataset.find.includes(q); tr.hidden = !ok; if (ok) shown++; });
    const empty = document.getElementById('plansEmpty');
    if (Object.keys(SC_PLANS).length) { empty.hidden = shown > 0; empty.textContent = 'No member matches.'; }
}
// ---------------------------------------------------------------- Batch Share Capital Billing
const SC_BATCH = <?= json_encode($batchJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const batchPicked = new Set();
function openBatch() {
    batchPicked.clear();
    document.getElementById('bFind').value = '';
    document.getElementById('bWaterOnly').checked = false;
    document.getElementById('bAmount').value = '';
    document.getElementById('bFreq').value = 'monthly';
    document.getElementById('bStart').value = iso(new Date());
    document.getElementById('bDay').value = String(new Date().getDate());
    document.getElementById('bTerm').value = '';
    renderBatch();
    batchPreview();
    openModal('batchModal');
}
function shownBatch() {
    const q = document.getElementById('bFind').value.trim().toLowerCase();
    const waterOnly = document.getElementById('bWaterOnly').checked;
    return SC_BATCH.filter(a => (!q || `${a.name} ${a.no} ${a.acct}`.toLowerCase().includes(q)) && (!waterOnly || a.water));
}
function renderBatch() {
    const rows = shownBatch();
    document.getElementById('bBody').innerHTML = rows.map(a => `<tr class="${batchPicked.has(a.id) ? 'on' : ''}">
        <td class="ck"><input type="checkbox" value="${a.id}" ${batchPicked.has(a.id) ? 'checked' : ''} aria-label="Bill ${esc(a.name)}" onchange="pickBatch(${a.id}, this.checked)"></td>
        <td><div class="who">${esc(a.name)}</div><div class="muted">Member no. ${esc(a.no)}</div></td>
        <td><span class="mono">${esc(a.acct)}</span><div class="muted">${esc(a.product)}</div></td>
        <td>${a.water ? esc(a.water) : '<span class="warn" style="margin:0">None yet: the cashier cannot collect until one is opened</span>'}</td></tr>`).join('');
    const empty = document.getElementById('bEmpty');
    empty.hidden = rows.length > 0;
    empty.textContent = SC_BATCH.length ? 'No member matches.' : 'Every open share capital account is already on fixed billing.';
    syncAll(rows);
    countBatch();
}
// the box that ticks every member shown: in the list's heading, and above the list on a phone
function syncAll(rows) {
    ['bAll', 'bAllPhone'].forEach(id => {
        const all = document.getElementById(id);
        all.checked = rows.length > 0 && rows.every(a => batchPicked.has(a.id));
        all.indeterminate = !all.checked && rows.some(a => batchPicked.has(a.id));
    });
}
function pickBatch(id, on) {
    on ? batchPicked.add(id) : batchPicked.delete(id);
    // only the row and the counts change: the list keeps its scroll and focus
    const box = document.querySelector(`#bBody input[value="${id}"]`);
    if (box) box.closest('tr').classList.toggle('on', on);
    syncAll(shownBatch());
    countBatch();
}
function tickShown(on) {
    shownBatch().forEach(a => on ? batchPicked.add(a.id) : batchPicked.delete(a.id));
    renderBatch();
}
function countBatch() {
    const n = batchPicked.size;
    const amt = Number(document.getElementById('bAmount').value || 0);
    document.getElementById('bCount').innerHTML = `Selected: <strong>${n}</strong> member${n === 1 ? '' : 's'}`
        + (amt > 0 && n ? `, <strong>&#8369;${(amt * n).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</strong> a posting` : '');
}
function batchPreview() {
    countBatch();
    const start = document.getElementById('bStart').value;
    const day = Number(document.getElementById('bDay').value || 0);
    const f = SC_FREQ[document.getElementById('bFreq').value];
    const amt = Number(document.getElementById('bAmount').value || 0);
    const termRaw = document.getElementById('bTerm').value.trim();
    const box = document.getElementById('bPreview');
    if (!start || !day || !f || !(amt > 0)) { box.textContent = 'Type the amount and choose the frequency, the start date and the posting day.'; return; }
    const first = firstDate(start, day);
    const dates = [first];
    for (let k = 1; k < 3; k++) { const p = dates[k - 1]; dates.push(dayIn(p.getFullYear(), p.getMonth() + f.months, day)); }
    box.innerHTML = `Each member: <strong>&#8369;${amt.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</strong> on <strong>${dates.map(d => fmtDate(iso(d))).join(', ')}</strong> and every ${esc(f.label.toLowerCase().replace('semi-annually', 'six months').replace('monthly', 'month').replace('quarterly', 'three months').replace('annually', 'year'))} afterwards.`
        + (termRaw === '' ? ' No due date (open).' : ` Due ${Number(termRaw)} day${Number(termRaw) === 1 ? '' : 's'} after each posting.`)
        + (first < new Date(new Date().toDateString()) ? '<br>The posting days already past are posted at once.' : '');
}
function checkBatch() {
    if (!batchPicked.size) { alert('Tick at least one member.'); return false; }
    document.getElementById('bAccounts').value = JSON.stringify(Array.from(batchPicked));
    return true;
}
document.addEventListener('keydown', ev => { if (ev.key === 'Escape') document.querySelectorAll('.overlay.open').forEach(o => o.classList.remove('open')); });
</script>
<?php endif; ?>
</body>
</html>
