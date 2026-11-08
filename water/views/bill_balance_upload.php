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
 * Upload Bill Balances: the open balances of the members' and customers' bills of Recurring Billing, Installment
 * Billing, One-Time Billing and Fixed Share Capital Billing, from a CSV or Excel file. The file may hold one row per
 * member (a balance column for each billing type, any of them blank) or one row per balance. Every balance is shown as
 * a line for review first (a line with a problem in red, its cells correctable, any line removable) and recorded only
 * when no line has a problem: every balance or none. The uploads are listed with their lines.
 */
require_once __DIR__ . '/../common/importReviewHelpers.php';
require_once __DIR__ . '/../common/billBalanceUploadHelpers.php';
if (!function_exists('e')) {
    function e($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
}
$pdo = getDB();
$ready = bbu_ready($pdo);
$canCreate = Access::can('water.bill_balance_upload', 'create');
$token = operationToken('bill_balance_upload');
$codes = bbu_codes($pdo);
$recurringCodes = [];
try {
    foreach ($pdo->query("SELECT DISTINCT c.code FROM recurring_plans p JOIN bill_codes c ON c.code_id = p.bill_code_id ORDER BY c.code")->fetchAll(PDO::FETCH_COLUMN) as $c) {
        if (isset($codes[strtoupper(trim((string)$c))])) {
            $recurringCodes[] = (string)$c;
        }
    }
} catch (Throwable $e) {
    $recurringCodes = [];
}
$scReady = class_exists('ShareCapitalBilling') && ShareCapitalBilling::ready();
$clearing = bbu_clearing_label();
$colsJs = [];
foreach (BBU_COLUMNS as $k => $c) {
    $col = ['key' => $k, 'label' => $c['label'], 'type' => $c['type'] ?? 'text', 'size' => $c['size'] ?? 'md', 'req' => !empty($c['req'])];
    if ($k === 'billing_type') {
        $col['options'] = array_map(fn($t) => [$t[0], $t[0]], array_values(BBU_TYPES));
        $col['blank'] = 'Choose';
        $col['req'] = true;
    } elseif ($k === 'charge_code') {
        $col['options'] = array_map(fn($c) => [(string)$c['code'], (string)$c['code']], array_values($codes));
        $col['blank'] = 'As set above';
    } elseif ($k === 'payment_mode') {
        $col['options'] = array_map(fn($m) => [$m[0], $m[0]], array_values(BBU_MODES));
        $col['blank'] = 'As set above';
    } elseif ($k === 'balance') {
        $col['req'] = true;
    } elseif ($k === 'due_date') {
        $col['blank'] = 'The bill date';
    }
    $colsJs[] = $col;
}
$groups = [];
foreach (BBU_TYPES as $k => [$label]) {
    $groups[$k] = $label;
}
$uploads = bbu_uploads($pdo, 25);
$shown = isset($_GET['upload']) ? bbu_upload($pdo, (int)$_GET['upload']) : null;
$success = (string)($_SESSION['success_msg'] ?? '');
unset($_SESSION['success_msg']);
$canOpen = fn(string $page) => userCanAccessPage($pdo, $page);
$typePages = ['recurring' => 'recurring_bill', 'installment' => 'installment_bill', 'one_time' => 'one_time_billing', 'share_capital' => 'share_capital_billing'];
$typeText = [
    'recurring' => 'A recurring bill on the member\'s subscription to the plan of its charge category. A member not enrolled gets the balance only: nothing more is billed.',
    'installment' => 'An installment contract for the balance, paid in the installments left, from the due date of the first one.',
    'one_time' => 'A one-time bill of its charge category.',
    'share_capital' => 'A share capital bill on the member\'s fixed billing. No journal entry: the collection is the deposit.',
];
$typeIcons = [
    'recurring' => '<path d="M21 12a9 9 0 1 1-3-6.7"/><polyline points="21 3 21 9 15 9"/>',
    'installment' => '<rect x="3" y="4" width="18" height="17" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="16" y1="2" x2="16" y2="6"/>',
    'one_time' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="14" x2="15" y2="14"/>',
    'share_capital' => '<path d="M12 2v20"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
];
$who = fn(array $u) => trim((string)($u['full_name'] ?: $u['username'] ?: ''));
$moneyCell = fn($v) => (float)$v > 0.004 ? '&#8369;' . number_format((float)$v, 2) : '<span class="bu-nil">None</span>';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="../common/fonts.css">
<link rel="stylesheet" href="../common/import-review.css?v=<?= (int)@filemtime(__DIR__ . '/../common/import-review.css') ?>">
<title>Upload Bill Balances</title>
<style>
.imp-defaults { grid-template-columns:repeat(4, minmax(0, 1fr)); }
.imp-defaults .water-class-field { margin:0; max-width:none; }
.imp-defaults .water-class-label { display:block; font-size:.74rem; font-weight:700; color:var(--text-2); margin-bottom:6px; }
.imp-defaults .water-class-hint { font-size:.72rem; color:var(--text-3); margin-top:5px; }
.alert-success { background:var(--green-bg); border:1px solid var(--green-border); color:var(--green); }
.bu-done { margin:14px 20px 4px; }

/* the four billing types */
.bu-types { display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:14px; margin-bottom:20px; }
.bu-type { background:var(--white); border:1px solid var(--border); border-radius:var(--radius); padding:16px 16px 14px; box-shadow:var(--shadow-sm); display:flex; flex-direction:column; gap:8px; }
.bu-type-head { display:flex; align-items:center; gap:10px; }
.bu-type-icon { width:34px; height:34px; border-radius:10px; background:var(--brand-light); color:var(--brand); display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.bu-type-icon svg { width:17px; height:17px; }
.bu-type h3 { margin:0; font-size:.88rem; font-weight:800; }
.bu-type p { margin:0; font-size:.76rem; color:var(--text-2); line-height:1.5; flex:1; }
.bu-type a { font-size:.76rem; font-weight:700; color:var(--brand-mid); text-decoration:none; }
.bu-type a:hover { text-decoration:underline; }
.bu-books { display:flex; gap:12px; align-items:flex-start; background:var(--white); border:1px solid var(--border); border-left:3px solid var(--brand-mid); border-radius:var(--radius-sm);
    padding:12px 16px; font-size:.8rem; color:var(--text-2); line-height:1.55; margin:-6px 0 20px; }
.bu-books strong { color:var(--text-1); }

/* the two layouts of the file */
.bu-tabs { display:flex; gap:4px; border-bottom:1px solid var(--border); margin:-4px 0 14px; overflow-x:auto; }
.bu-tab { background:none; border:0; border-bottom:2px solid transparent; padding:9px 12px 10px; font-family:var(--font); font-size:.8rem; font-weight:700; color:var(--text-2); cursor:pointer; white-space:nowrap; margin-bottom:-1px; }
.bu-tab[aria-selected="true"] { color:var(--brand); border-bottom-color:var(--brand); }
.bu-tab:hover { color:var(--brand); }
.bu-layout-sub { font-size:.76rem; color:var(--text-2); margin:0 0 10px; line-height:1.5; }
.bu-tpl { display:flex; gap:10px; flex-wrap:wrap; margin-top:14px; }

/* the uploads */
.bu-table-wrap { overflow-x:auto; }
table.bu-table { width:100%; border-collapse:collapse; font-size:.8rem; }
table.bu-table th { text-align:left; font-size:.7rem; font-weight:700; color:var(--text-2); padding:10px 12px; border-bottom:1px solid var(--border); background:var(--surface); white-space:nowrap; }
table.bu-table td { padding:10px 12px; border-bottom:1px solid var(--border); vertical-align:top; }
table.bu-table tr:last-child td { border-bottom:0; }
table.bu-table .num { text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; }
table.bu-table tr.is-shown td { background:var(--brand-light); }
table.bu-table tfoot td { font-weight:800; background:var(--surface); border-top:1px solid var(--border-2); }
.bu-no { font-family:var(--mono); font-weight:700; color:var(--brand); text-decoration:none; white-space:nowrap; }
.bu-no:hover { text-decoration:underline; }
.bu-sub { display:block; color:var(--text-3); font-size:.72rem; margin-top:2px; }
.bu-nil { color:var(--text-3); }
.bu-empty { padding:28px 20px; text-align:center; color:var(--text-2); font-size:.84rem; }
.bu-status-open { color:var(--amber); font-weight:700; }
.bu-status-paid { color:var(--green); font-weight:700; }
.bu-status-off { color:var(--text-3); font-weight:700; }

@media (max-width: 1100px) {
    .bu-types { grid-template-columns:repeat(2, minmax(0, 1fr)); }
    .imp-defaults { grid-template-columns:repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 760px) {
    .bu-types { grid-template-columns:1fr; gap:10px; }
    .bu-type { padding:14px; }
    .bu-tpl .btn { flex:1; }
    table.bu-table thead { display:none; }
    table.bu-table, table.bu-table tbody, table.bu-table tfoot, table.bu-table tr, table.bu-table td { display:block; width:100%; }
    table.bu-table tr { padding:10px 14px; border-bottom:1px solid var(--border); }
    table.bu-table td { border:0; padding:3px 0; display:flex; justify-content:space-between; gap:12px; text-align:right; }
    table.bu-table td::before { content:attr(data-label); font-weight:700; color:var(--text-2); font-size:.72rem; text-align:left; flex-shrink:0; }
    table.bu-table td.num { text-align:right; }
    table.bu-table tfoot td { border-top:0; }
    table.bu-table td.bu-hide-sm { display:none; }
    table.bu-table td.bu-foot-label::before { content:none; }
}
@media (max-width: 420px) { .imp-defaults { grid-template-columns:1fr; } }
</style>
</head>
<body>
<?php require_once '../common/navbar.php'; ?>
<div class="main-content">
<main class="page">
    <a class="back-link" href="billing_management.php">&larr; Back to Billing Management</a>
    <div class="page-header">
        <div>
            <h1 class="page-title">Upload Bill Balances</h1>
            <p class="page-sub">Bring in the unpaid balances of the members' and customers' bills from a file: recurring, installment, one-time and fixed share capital. A member may have one, two, three or all four of them. Every balance is shown for review first: correct a cell or remove a line, then record them. Nothing is recorded while a line has a problem, and then every balance or none.</p>
        </div>
        <?php if (class_exists('Access') && Access::can('coop.consolidated_import', 'create')): ?>
            <a class="btn btn-ghost" href="<?= e(url('coop.consolidated_import')) ?>" title="The members with their savings, loans and bill balances from one file">Consolidated Import</a>
        <?php endif; ?>
    </div>

    <?php if (!$ready): ?>
        <div class="alert alert-error">Run the database update database/migrations/2027_04_18_bill_balance_upload.sql first.</div>
    <?php endif; ?>

    <div class="bu-types">
        <?php foreach (BBU_TYPES as $k => [$short, $page]): ?>
            <section class="bu-type" aria-label="<?= e($page) ?>">
                <div class="bu-type-head"><span class="bu-type-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $typeIcons[$k] ?></svg></span><h3><?= e($page) ?></h3></div>
                <p><?= e($typeText[$k]) ?><?= $k === 'share_capital' && !$scReady ? ' Not installed on this database.' : '' ?></p>
                <?php if ($canOpen($typePages[$k])): ?><a href="<?= e($typePages[$k]) ?>.php">Open <?= e($page) ?></a><?php endif; ?>
            </section>
        <?php endforeach; ?>
    </div>
    <div class="bu-books">
        <span>The recurring, installment and one-time balances are receivables: each gets its invoice, debit the bill code's A/R account, credit <strong><?= e($clearing) ?></strong> (an opening balance: no income is recognised again, no stock moves, no member discount is taken). The cashier collects them as any bill, and they are on the Individual Statement of Account.</span>
    </div>

    <section class="card" aria-labelledby="defTitle">
        <div class="card-head"><div><h2 class="card-title" id="defTitle">Values for the blank cells</h2><p class="card-sub">A cell of the file left blank takes the value here; a cell filled in keeps its own. Changing a value here checks the lines again.</p></div></div>
        <div class="card-body">
            <form id="irDefaults" class="imp-defaults" onsubmit="return false">
                <div><label class="field-label" for="dDate">Bill date</label><input class="field" type="date" name="bill_date" id="dDate" value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>">
                    <div class="field-hint">The date of the balances (their invoices are dated it).</div></div>
                <div><label class="field-label" for="dDue">Due date</label><input class="field" type="date" name="due_date" id="dDue">
                    <div class="field-hint">Blank: the bill date. An installment balance: the first installment's.</div></div>
                <div><label class="field-label" for="dRec">Recurring charge category</label>
                    <select class="field" name="recurring_code" id="dRec"><option value="">Each line's own</option>
                        <?php foreach ($recurringCodes as $c): ?><option value="<?= e($c) ?>"><?= e($c) ?></option><?php endforeach; ?>
                    </select>
                    <div class="field-hint">The bill codes of the recurring plans.</div></div>
                <div><label class="field-label" for="dInst">Installment charge category</label>
                    <select class="field" name="installment_code" id="dInst"><option value="">Each line's own</option>
                        <?php foreach ($codes as $c): ?><option value="<?= e($c['code']) ?>"><?= e($c['code'] . ' (' . $c['type'] . ')') ?></option><?php endforeach; ?>
                    </select></div>
                <div><label class="field-label" for="dOne">One-time charge category</label>
                    <select class="field" name="one_time_code" id="dOne"><option value="">Each line's own</option>
                        <?php foreach ($codes as $c): ?><option value="<?= e($c['code']) ?>"><?= e($c['code'] . ' (' . $c['type'] . ')') ?></option><?php endforeach; ?>
                    </select></div>
                <div><label class="field-label" for="dN">Installments left</label><input class="field" type="number" min="1" max="120" step="1" name="installments" id="dN" value="1">
                    <div class="field-hint">How many installments an installment balance is paid in.</div></div>
                <div><label class="field-label" for="dMode">Payment mode</label>
                    <select class="field" name="payment_mode" id="dMode"><?php foreach (BBU_MODES as $k => [$label]): ?><option value="<?= e($label) ?>"<?= $k === 'monthly' ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
                <div><?= render_water_class_field(null, true, 'dClass', 'class_id', 'field') ?></div>
            </form>
        </div>
    </section>

    <div id="irStart" class="imp-start">
        <section class="card" aria-labelledby="fileTitle">
            <div class="card-head"><div><h2 class="card-title" id="fileTitle">The file</h2><p class="card-sub">CSV (Comma delimited) or Excel workbook (.xlsx); the first line holds the column names.</p></div></div>
            <div class="card-body">
                <form id="irReadForm" enctype="multipart/form-data">
                    <label class="imp-drop">
                        <input type="file" name="file" id="irFile" accept=".csv,.txt,.tsv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required
                               onchange="document.getElementById('irFilePicked').textContent = this.files[0] ? this.files[0].name : ''">
                        <div class="imp-drop-title">Choose the file, or drop it here</div>
                        <div class="imp-drop-sub">One row per member with a column for each billing type, or one row per balance</div>
                        <div class="imp-drop-file" id="irFilePicked"></div>
                    </label>
                    <div class="imp-step-foot">
                        <button type="submit" class="btn btn-primary" id="irReadBtn"<?= $ready ? '' : ' disabled' ?>><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>Read the file for review</button>
                    </div>
                    <div class="alert alert-error" id="irReadMsg" hidden style="margin:14px 0 0"></div>
                    <p class="imp-note">Nothing is recorded yet: every balance is shown for review first, each as a line (a row of the file with balances of three billing types is three lines). Leave a balance cell blank when the member has none of that billing type. The same balance is not uploaded twice: a line that an earlier upload already holds is shown in red.</p>
                </form>
            </div>
        </section>
        <section class="card" aria-labelledby="colsTitle">
            <div class="card-head"><div><h2 class="card-title" id="colsTitle">The columns</h2><p class="card-sub">Either layout is read, or a mix of both. Other names are understood too (Account, Amount, Charge Category, Type ...).</p></div></div>
            <div class="card-body">
                <div class="bu-tabs" role="tablist" aria-label="Layout of the file">
                    <button type="button" class="bu-tab" role="tab" id="tabWide" aria-controls="layWide" aria-selected="true">One row per member</button>
                    <button type="button" class="bu-tab" role="tab" id="tabLong" aria-controls="layLong" aria-selected="false" tabindex="-1">One row per balance</button>
                </div>
                <?php foreach (['wide' => [BBU_WIDE_COLUMNS, 'A balance column for each billing type: fill in the ones the member has, leave the others blank.', 'layWide', 'tabWide'],
                                'long' => [BBU_COLUMNS, 'A Billing type column: a member with several balances has several rows (two one-time charges of different categories, for example).', 'layLong', 'tabLong']] as $lay => [$cols, $sub, $id, $tab]): ?>
                    <div id="<?= $id ?>" role="tabpanel" aria-labelledby="<?= $tab ?>"<?= $lay === 'wide' ? '' : ' hidden' ?>>
                        <p class="bu-layout-sub"><?= e($sub) ?> Required in bold.</p>
                        <dl class="imp-cols">
                            <?php foreach ($cols as $k => $c): ?>
                                <div><dt><?= !empty($c['req']) || ($lay === 'long' && in_array($k, ['billing_type', 'balance'], true)) ? '<strong>' . e($k) . '</strong>' : e($k) ?></dt><dd><?= e($c['label']) ?><?= !empty($c['hint']) ? '<span>' . e($c['hint']) . '</span>' : '' ?></dd></div>
                            <?php endforeach; ?>
                        </dl>
                        <div class="bu-tpl">
                            <a class="btn btn-ghost" href="../process/billBalanceUpload.php?action=template&amp;layout=<?= $lay ?>&amp;format=xlsx"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>Excel template</a>
                            <a class="btn btn-ghost" href="../process/billBalanceUpload.php?action=template&amp;layout=<?= $lay ?>&amp;format=csv"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>CSV template</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    </div>

    <section class="card" id="irReview" hidden aria-labelledby="reviewTitle">
        <div class="card-head">
            <div class="ir-file">
                <span class="ir-file-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></span>
                <div><h2 class="card-title" id="reviewTitle"><span id="irFileName">The file</span></h2>
                    <p>Each balance is a line, with the row of the file it came from. Correct a cell by typing in it or choosing from its list (the line is checked again at once), remove a balance with &times;. Record balances records every line, or none while a line in red still has a problem.</p></div>
            </div>
            <button type="button" class="btn btn-ghost" id="irRestart">Start over</button>
        </div>
        <div class="ir-bar">
            <div class="ir-counts">
                <span><strong id="irN">0</strong> <span>balances</span></span>
                <span class="is-bad" id="irBadWrap" hidden><strong id="irBad">0</strong> with a problem</span>
                <span>Total <strong id="irTotal">&#8369;0.00</strong></span>
                <span id="irRemovedWrap" hidden><strong id="irRemovedN">0</strong> removed <button type="button" class="btn-link" id="irUndo">Put back the last</button></span>
                <span class="ir-checking" id="irChecking" hidden>Checking</span>
            </div>
            <div class="ir-tools">
                <input type="search" class="search" id="irFind" placeholder="Find in the lines" aria-label="Find in the lines">
                <label class="check-line"><input type="checkbox" id="irBadOnly"> Only lines with a problem</label>
                <button type="button" class="btn ir-danger" id="irRemoveBad" hidden>Remove the lines with a problem</button>
            </div>
        </div>
        <div class="ir-groups" id="irGroups" hidden aria-label="Balances by billing type"></div>
        <div class="alert alert-error ir-general" id="irGeneral" hidden></div>
        <div class="ir-scroll">
            <table class="ir-table" id="irTable"><thead></thead><tbody class="ir-none" id="irNone" hidden><tr><td colspan="20">No line matches.</td></tr></tbody></table>
        </div>
        <div class="ir-actions">
            <div id="irStatus"></div>
            <div class="ir-actions-btns">
                <button type="button" class="btn btn-ghost" id="irRecheck">Check again</button>
                <button type="button" class="btn btn-primary" id="irPost" disabled>Record <span id="irPostN">0 balances</span></button>
            </div>
        </div>
    </section>

    <section class="card" id="upload" aria-labelledby="upTitle">
        <div class="card-head"><div><h2 class="card-title" id="upTitle">Uploads</h2><p class="card-sub">The balances uploaded, newest first. Open an upload to see its lines, the bill each one made and what is still unpaid on it.</p></div></div>
        <?php if ($success !== ''): ?><div class="alert alert-success bu-done" role="status"><?= e($success) ?></div><?php endif; ?>
        <?php if (!$uploads): ?>
            <div class="bu-empty">No balance has been uploaded yet.</div>
        <?php else: ?>
            <div class="bu-table-wrap">
                <table class="bu-table">
                    <thead><tr><th>Upload</th><th>File</th><th class="num">Recurring</th><th class="num">Installment</th><th class="num">One-Time</th><th class="num">Fixed Share Capital</th><th class="num">Total</th></tr></thead>
                    <tbody>
                    <?php foreach ($uploads as $u): ?>
                        <tr class="<?= $shown && (int)$shown['upload_id'] === (int)$u['upload_id'] ? 'is-shown' : '' ?>">
                            <td data-label="Upload"><span><a class="bu-no" href="?upload=<?= (int)$u['upload_id'] ?>#upload"><?= e($u['upload_no'] ?: 'BAL-' . $u['upload_id']) ?></a><span class="bu-sub"><?= e($u['created_at'] ? date('m/d/Y g:i A', strtotime($u['created_at'])) : '') ?><?= $who($u) !== '' ? ', ' . e($who($u)) : '' ?></span></span></td>
                            <td data-label="File"><span><?= e($u['file_name'] ?: 'No file name') ?><span class="bu-sub"><?= (int)$u['line_count'] ?> balance<?= (int)$u['line_count'] === 1 ? '' : 's' ?></span></span></td>
                            <?php foreach (array_keys(BBU_TYPES) as $t): $b = $u['by_type'][$t] ?? [0, 0.0]; ?>
                                <td class="num" data-label="<?= e(BBU_TYPES[$t][0]) ?>"><span><?= $moneyCell($b[1]) ?><?php if ($b[0]): ?><span class="bu-sub"><?= (int)$b[0] ?> line<?= $b[0] === 1 ? '' : 's' ?></span><?php endif; ?></span></td>
                            <?php endforeach; ?>
                            <td class="num" data-label="Total"><strong>&#8369;<?= number_format((float)$u['total_amount'], 2) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($shown): ?>
        <section class="card" aria-labelledby="linesTitle">
            <div class="card-head">
                <div><h2 class="card-title" id="linesTitle"><?= e($shown['upload_no']) ?></h2>
                    <p class="card-sub"><?= e($shown['file_name'] ?: 'No file name') ?>, uploaded <?= e($shown['created_at'] ? date('m/d/Y g:i A', strtotime($shown['created_at'])) : '') ?><?= $who($shown) !== '' ? ' by ' . e($who($shown)) : '' ?>: <?= (int)$shown['line_count'] ?> balance<?= (int)$shown['line_count'] === 1 ? '' : 's' ?> for &#8369;<?= number_format((float)$shown['total_amount'], 2) ?>.</p></div>
                <a class="btn btn-ghost" href="bill_balance_upload.php#upload">Close</a>
            </div>
            <div class="bu-table-wrap">
                <table class="bu-table">
                    <thead><tr><th>Line</th><th>Account</th><th>Billing type</th><th>Charge category</th><th class="num">Balance</th><th>Bill date</th><th>Due date</th><th>Bill</th><th class="num">Unpaid now</th></tr></thead>
                    <tbody>
                    <?php $sumOpen = 0.0; foreach ($shown['lines'] as $l):
                        $name = trim($l['lastname'] . ', ' . $l['firstname'] . ($l['middlename'] ? ' ' . $l['middlename'] : ''), ', ');
                        $off = in_array($l['bill_status'], ['cancelled', 'deleted'], true);
                        $sumOpen += $off ? 0 : $l['open'];
                        $state = $off ? ['bu-status-off', ucfirst($l['bill_status'])] : ($l['open'] > 0.004 ? ['bu-status-open', 'Unpaid'] : ['bu-status-paid', 'Paid']); ?>
                        <tr>
                            <td data-label="Line"><span><?= (int)$l['line_no'] ?><?php if ($l['file_row']): ?><span class="bu-sub">Row <?= (int)$l['file_row'] ?></span><?php endif; ?></span></td>
                            <td data-label="Account"><span><?= e($name ?: 'Account ' . $l['water_account_id']) ?><span class="bu-sub"><?= e($l['account_number'] ?: 'ID ' . $l['water_account_id']) ?></span></span></td>
                            <td data-label="Billing type"><?= e(BBU_TYPES[$l['billing_type']][0] ?? $l['billing_type']) ?><?php if ($l['installments']): ?><span class="bu-sub"><?= (int)$l['installments'] ?> installment<?= (int)$l['installments'] === 1 ? '' : 's' ?></span><?php endif; ?></td>
                            <td data-label="Charge category"><?= $l['billing_type'] === 'share_capital' ? 'Account ' . e($l['ref']) : e($l['code'] ?? '') ?></td>
                            <td class="num" data-label="Balance">&#8369;<?= number_format((float)$l['amount'], 2) ?></td>
                            <td data-label="Bill date"><?= e(bbu_date($l['bill_date'])) ?></td>
                            <td data-label="Due date"><?= e(bbu_date($l['due_date'])) ?></td>
                            <td data-label="Bill"><span><?= $l['billing_type'] === 'share_capital' ? 'Bill #' . (int)$l['bill_id'] : ($l['ref'] !== '' ? 'Ref #' . e($l['ref']) : '#' . (int)$l['bill_id']) ?><span class="bu-sub <?= $state[0] ?>"><?= e($state[1]) ?></span></span></td>
                            <td class="num" data-label="Unpaid now"><?= $off ? '<span class="bu-nil">None</span>' : '&#8369;' . number_format($l['open'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot><tr><td class="bu-foot-label" colspan="4">Total</td><td class="num" data-label="Balance">&#8369;<?= number_format((float)$shown['total_amount'], 2) ?></td><td colspan="3" class="bu-hide-sm"></td><td class="num" data-label="Unpaid now">&#8369;<?= number_format($sumOpen, 2) ?></td></tr></tfoot>
                </table>
            </div>
        </section>
    <?php elseif (isset($_GET['upload'])): ?>
        <div class="alert alert-error">That upload was not found.</div>
    <?php endif; ?>
</main>
</div>
<script>
window.IMPORT_REVIEW = {
    endpoint: '../process/billBalanceUpload.php',
    csrf: <?= json_encode(csrfToken()) ?>,
    token: <?= json_encode($token) ?>,
    cols: <?= json_encode($colsJs, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    noun: ['balance', 'balances'],
    total: 'balance',
    groups: <?= json_encode($groups, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    defaults: '#irDefaults',
    canPost: <?= $canCreate && $ready ? 'true' : 'false' ?>,
    confirm: 'Record {n}? Each becomes a bill of its billing type; the recurring, installment and one-time balances get their invoices.',
    confirmTitle: 'Record balances',
    confirmOk: 'Record balances'
};
(function () {
    // the two layouts of the file: tabs (arrow keys move between them)
    const tabs = Array.from(document.querySelectorAll('.bu-tab'));
    const show = tab => {
        tabs.forEach(t => {
            const on = t === tab;
            t.setAttribute('aria-selected', on ? 'true' : 'false');
            t.tabIndex = on ? 0 : -1;
            document.getElementById(t.getAttribute('aria-controls')).hidden = !on;
        });
    };
    tabs.forEach((t, i) => {
        t.addEventListener('click', () => show(t));
        t.addEventListener('keydown', ev => {
            if (ev.key !== 'ArrowRight' && ev.key !== 'ArrowLeft') return;
            const next = tabs[(i + (ev.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length];
            show(next);
            next.focus();
        });
    });
})();
</script>
<script src="../common/import-review.js?v=<?= (int)@filemtime(__DIR__ . '/../common/import-review.js') ?>"></script>
</body>
</html>
