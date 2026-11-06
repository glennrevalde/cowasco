<?php
/* AUTH_GUARD_START */
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../process/accessControlHelpers.php';
$__authGuardPdo = getDB();
requirePageAccess($__authGuardPdo, basename(__FILE__, '.php'));
unset($__authGuardPdo);
/* AUTH_GUARD_END */

/**
 * views/disconnection_management.php
 * Manual Disconnection Management
 *
 * This page only tells operators who is eligible for disconnection.
 * It does NOT automatically disconnect anyone.
 */

require_once __DIR__ . '/../database/config.php';

require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../process/disconnectionHelpers.php';

$pdo = mdh_pdo();
$search = trim((string)($_GET['q'] ?? ''));
$billCodeId = max(0, (int)($_GET['bill_code_id'] ?? 0));
$billCodes = [];
$selectedBillCode = null;

try {
    $billCodes = mdh_fetch_bill_codes($pdo);
    foreach ($billCodes as $billCode) {
        if ((int)($billCode['code_id'] ?? 0) === $billCodeId) {
            $selectedBillCode = $billCode;
            break;
        }
    }
    if ($billCodeId > 0 && $selectedBillCode === null) {
        throw new RuntimeException('The selected bill code was not found.');
    }
    $forDisconnection = mdh_fetch_for_disconnection($pdo, $search, $billCodeId);
    $disconnectedMembers = mdh_fetch_disconnected_members($pdo, $search);
} catch (Throwable $e) {
    $forDisconnection = [];
    $disconnectedMembers = [];
    $_SESSION['error_msg'] = $e->getMessage();
}

$activeCandidates = array_values(array_filter($forDisconnection, function (array $row): bool {
    return ($row['connection_status'] ?? 'active') !== 'disconnected';
}));

$alreadyDisconnectedCandidates = array_values(array_filter($forDisconnection, function (array $row): bool {
    return ($row['connection_status'] ?? 'active') === 'disconnected';
}));

$success = $_SESSION['success_msg'] ?? '';
$error = $_SESSION['error_msg'] ?? '';
unset($_SESSION['success_msg'], $_SESSION['error_msg']);

function mdh_badge_class(string $sourceType): string
{
    switch ($sourceType) {
        case 'water': return 'badge-blue';
        case 'recurring': return 'badge-purple';
        case 'onetime': return 'badge-orange';
        case 'installment': return 'badge-green';
        default: return 'badge-gray';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Disconnection Management</title>
    <style>
        @import url('../common/fonts.css');
        :root {
            --bg: #f7fafc;
            --card: #ffffff;
            --text: #172033;
            --muted: #667085;
            --line: #e4e7ec;
            --primary: #0f766e;
            --primary-dark: #0b5f59;
            --danger: #b42318;
            --danger-bg: #fff1f0;
            --success: #027a48;
            --success-bg: #ecfdf3;
            --warning-bg: #fffaeb;
            --warning: #b54708;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font-family: 'Sora', Arial, sans-serif;
            font-size: 14px;
        }
        .page-wrap { max-width: 1320px; margin: 0 auto; padding: 28px 20px 60px; }
        .page-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 18px;
        }
        .page-actions { display: flex; align-items: center; gap: 10px; flex-shrink: 0; padding-top: 6px; }
        .page-actions .btn { white-space: nowrap; }
        .eyebrow { font-size: 12px; color: var(--primary); font-weight: 700; text-transform: uppercase; letter-spacing: .08em; }
        h1 { margin: 4px 0 8px; font-size: 28px; }
        .lead { margin: 0; color: var(--muted); line-height: 1.55; max-width: 900px; }
        .card {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 18px;
            box-shadow: 0 8px 24px rgba(15, 23, 42, .04);
            overflow: hidden;
            margin-top: 16px;
        }
        .card-head {
            padding: 18px 20px;
            border-bottom: 1px solid var(--line);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            flex-wrap: wrap;
        }
        .card-title { margin: 0; font-size: 18px; font-weight: 700; }
        .count-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 34px;
            padding: 5px 10px;
            border-radius: 999px;
            background: #eef6ff;
            color: #175cd3;
            font-size: 12px;
            font-weight: 700;
        }
        .search-form { display: flex; gap: 8px; align-items: center; }
        .search-form { flex-wrap: wrap; }
        .search-form input, .search-form select {
            width: 320px;
            max-width: 60vw;
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 11px 12px;
            font-family: inherit;
            background: #fff;
            outline: none;
        }
        .search-form select { width: 270px; }
        /* one row on a computer: the bill code (the enhanced drop-down), the search, the buttons */
        .search-form > .ds-select-enhanced { flex: 0 1 320px; width: auto; min-width: 220px; }
        .search-form > input[type=text] { flex: 1 1 320px; width: auto; max-width: none; }
        .search-form button, .btn {
            border: 0;
            border-radius: 12px;
            padding: 11px 14px;
            font-family: inherit;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            white-space: nowrap;
        }
        .btn-primary { background: var(--primary); color: #fff; }
        .btn-primary:hover { background: var(--primary-dark); }
        .btn-danger { background: var(--danger); color: #fff; }
        .btn-muted { background: #f2f4f7; color: #344054; }
        .btn-success { background: var(--success); color: #fff; }
        .btn[disabled] { opacity: .5; cursor: not-allowed; }
        .alert {
            border-radius: 14px;
            padding: 13px 15px;
            margin: 14px 0;
            border: 1px solid transparent;
            font-weight: 600;
        }
        .alert-success { background: var(--success-bg); color: var(--success); border-color: #abefc6; }
        .alert-error { background: var(--danger-bg); color: var(--danger); border-color: #fecdca; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px 14px; border-bottom: 1px solid var(--line); vertical-align: top; text-align: left; }
        th { font-size: 12px; color: #475467; text-transform: uppercase; letter-spacing: .04em; background: #f9fafb; }
        tr:hover td { background: #fcfcfd; }
        .muted { color: var(--muted); }
        .small { font-size: 12px; }
        .amount { font-weight: 800; }
        .status-disconnected { color: var(--danger); font-weight: 800; }
        .status-active { color: var(--success); font-weight: 800; }
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 5px 9px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
        }
        .badge-blue { background: #eff8ff; color: #175cd3; }
        .badge-purple { background: #f4f3ff; color: #5925dc; }
        .badge-orange { background: #fff6ed; color: #c4320a; }
        .badge-green { background: #ecfdf3; color: #027a48; }
        .badge-gray { background: #f2f4f7; color: #344054; }
        .reason-input {
            width: 230px;
            max-width: 100%;
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 9px 10px;
            font-family: inherit;
            margin-bottom: 8px;
        }
        .empty {
            padding: 28px 20px;
            text-align: center;
            color: var(--muted);
        }
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
            margin: 18px 0;
        }
        .summary-card {
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 16px;
        }
        .summary-card .label { color: var(--muted); font-size: 12px; font-weight: 700; text-transform: uppercase; }
        .summary-card .value { font-size: 28px; font-weight: 800; margin-top: 6px; }
        .note {
            margin-top: 12px;
            padding: 14px 16px;
            border-radius: 14px;
            background: var(--warning-bg);
            border: 1px solid #fedf89;
            color: var(--warning);
            line-height: 1.5;
        }
        .bulk-action {
            margin-top: 16px;
            padding: 18px 20px;
            border: 1px solid #fecdca;
            border-radius: 16px;
            background: var(--danger-bg);
        }
        .bulk-action h2 { margin: 0 0 6px; font-size: 17px; color: var(--danger); }
        .bulk-action p { margin: 0 0 13px; color: #7a271a; line-height: 1.5; }
        .bulk-form { display: flex; align-items: end; gap: 10px; flex-wrap: wrap; }
        .bulk-form label { display: grid; gap: 6px; min-width: 320px; flex: 1; font-size: 12px; font-weight: 700; color: #7a271a; }
        .bulk-form input[type=text] { width: 100%; border: 1px solid #fda29b; border-radius: 10px; padding: 10px 11px; font-family: inherit; background: #fff; }
        @media print {
            .search-form, .action-col, .page-actions, .btn { display: none !important; }
            body { background: #fff; }
            .page-wrap { max-width: none; padding: 0; }
            .card { box-shadow: none; border-radius: 0; }
        }
        @media (max-width: 860px) {
            .page-head { display: block; }
            .page-actions { margin-top: 14px; padding-top: 0; flex-wrap: wrap; }
            .summary-grid { grid-template-columns: 1fr; }
            .search-form { width: 100%; }
            .search-form input, .search-form select { width: 100%; max-width: none; }
            .search-form > .ds-select-enhanced, .search-form > input[type=text] { flex: 1 1 100%; }
            .bulk-form label { min-width: 100%; }
        }
    </style>
<style id="print-readability-fix">@page{size:A4 landscape;margin:10mm}@media print{body{font-family:Arial,Helvetica,sans-serif!important}.card{border:0!important}.table-wrap{overflow:visible!important}table{width:100%!important;table-layout:auto!important;font-size:10pt!important}th{font-size:9.5pt!important}th,td{padding:7px 8px!important;white-space:normal!important;overflow-wrap:anywhere}thead{display:table-header-group}tr{break-inside:avoid;page-break-inside:avoid}.summary-grid{break-inside:avoid;page-break-inside:avoid}}</style>
</head>
<body>
<?php
/* Include your existing navbar when available. */
$navbar = __DIR__ . '/../common/navbar.php';
if (file_exists($navbar)) {
    include $navbar;
}
?>
<div class="main-content">
<div class="page-wrap">
    <div class="page-head">
        <div>
            <h1>Disconnection Management</h1>
            <p class="lead">
                This page identifies customers with unpaid bills whose disconnection dates have arrived. You may filter by a specific bill code and manually disconnect one customer or all eligible customers under that bill code. Nothing is disconnected automatically.
            </p>
        </div>
        <div class="page-actions">
            <a href="water_bills_collection.php" class="btn btn-muted">Back to Collection</a>
            <button onclick="window.print()" class="btn btn-primary">Print List</button>
        </div>
    </div>

    <?php if ($success): ?><div class="alert alert-success"><?= wbr_e($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= wbr_e($error) ?></div><?php endif; ?>

    <form method="get" class="search-form">
        <select name="bill_code_id" aria-label="Filter by bill code">
            <option value="0">All Bill Codes</option>
            <?php foreach ($billCodes as $billCode): ?>
                <option value="<?= (int)$billCode['code_id'] ?>" <?= (int)$billCode['code_id'] === $billCodeId ? 'selected' : '' ?>>
                    <?= wbr_e(trim((string)$billCode['code'] . ' — ' . (string)($billCode['description'] ?? ''))) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <input type="text" name="q" value="<?= wbr_e($search) ?>" placeholder="Search account #, member name, bill code, rate code..." data-live-search>
        <button type="submit" class="btn-primary">Apply Filter</button>
        <?php if ($search !== '' || $billCodeId > 0): ?><a class="btn btn-muted" href="disconnection_management.php">Clear</a><?php endif; ?>
    </form>

    <div id="dmResults" data-live-results>
    <div class="summary-grid">
        <div class="summary-card">
            <div class="label">Need Manual Disconnection</div>
            <div class="value"><?= count($activeCandidates) ?></div>
        </div>
        <div class="summary-card">
            <div class="label">Already Disconnected</div>
            <div class="value"><?= count($disconnectedMembers) ?></div>
        </div>
        <div class="summary-card">
            <div class="label">Unpaid Bills Flagged</div>
            <div class="value"><?= count($forDisconnection) ?></div>
        </div>
    </div>

    <div class="note">
        Rule used by this page: <strong>unpaid bill + disconnection_date &lt;= today</strong>. It covers water bills, recurring bills, one-time bills, and installment schedules.<?= $selectedBillCode ? ' The current list is limited to bill code <strong>' . wbr_e((string)$selectedBillCode['code']) . '</strong>.' : '' ?> The final disconnection action is always manual.
    </div>

    <?php if ($selectedBillCode): ?>
    <section class="bulk-action action-col">
        <h2>Disconnect Eligible Members for <?= wbr_e((string)$selectedBillCode['code']) ?></h2>
        <p>This action marks each active member in the filtered list as disconnected once. It does not disconnect members whose bills are paid, whose disconnection date has not arrived, or who are already disconnected.</p>
        <form class="bulk-form" method="post" action="../process/updateManualDisconnection.php" data-confirm="Disconnect <?= count($activeCandidates) ?> eligible member(s) for bill code <?= wbr_e((string)$selectedBillCode['code']) ?>?">
            <input type="hidden" name="csrf_token" value="<?= wbr_e(csrfToken()) ?>">
            <input type="hidden" name="action" value="disconnect_bill_code">
            <input type="hidden" name="bill_code_id" value="<?= $billCodeId ?>">
            <input type="hidden" name="return_q" value="<?= wbr_e($search) ?>">
            <input type="hidden" name="return_bill_code_id" value="<?= $billCodeId ?>">
            <label>Reason / remarks for this bill-code disconnection
                <input type="text" name="reason" maxlength="500" placeholder="Optional reason applied to each disconnected member">
            </label>
            <button type="submit" class="btn btn-danger" <?= !$activeCandidates ? 'disabled' : '' ?>>Disconnect <?= count($activeCandidates) ?> Eligible Member<?= count($activeCandidates) === 1 ? '' : 's' ?></button>
        </form>
    </section>
    <?php endif; ?>

    <section class="card">
        <div class="card-head">
            <h2 class="card-title">For Manual Disconnection <span class="count-pill"><?= count($activeCandidates) ?></span></h2>
        </div>
        <div class="table-wrap">
            <?php if (!$activeCandidates): ?>
                <div class="empty">No active customers currently eligible for manual disconnection.</div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th>Bill</th>
                            <th>Due / Disconnection</th>
                            <th>Balance</th>
                            <th>Status</th>
                            <th class="action-col">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($activeCandidates as $row): ?>
                        <tr>
                            <td>
                                <strong><?= wbr_e($row['member_name']) ?></strong><br>
                                <span class="small muted">Account #: <?= wbr_e($row['account_number'] ?: '—') ?></span><br>
                                <span class="small muted">Rate Code: <?= wbr_e(trim(($row['rc_code'] ?? '') . ' ' . ($row['rc_name'] ?? '')) ?: '—') ?></span>
                            </td>
                            <td>
                                <span class="badge <?= mdh_badge_class((string)$row['source_type']) ?>"><?= wbr_e($row['bill_type']) ?></span><br>
                                <strong><?= wbr_e($row['bill_label']) ?></strong><br>
                                <span class="small muted">Bill Code: <?= wbr_e($row['bill_code'] ?? '—') ?></span><br>
                                <span class="small muted">Bill Date: <?= mdh_date($row['bill_date']) ?></span>
                            </td>
                            <td>
                                <strong>Due:</strong> <?= mdh_date($row['due_date']) ?><br>
                                <strong>Disconnect:</strong> <?= mdh_date($row['disconnection_date']) ?><br>
                                <span class="small muted"><?= (int)$row['days_after_disconnection_date'] ?> day(s) after disconnection date</span>
                            </td>
                            <td class="amount">₱<?= mdh_money($row['balance']) ?></td>
                            <td><span class="status-active">Active</span></td>
                            <td class="action-col">
                                <form method="post" action="../process/updateManualDisconnection.php" data-confirm="Mark this customer as disconnected?">
                                    <input type="hidden" name="csrf_token" value="<?= wbr_e(csrfToken()) ?>">
                                    <input type="hidden" name="action" value="disconnect">
                                    <input type="hidden" name="return_q" value="<?= wbr_e($search) ?>">
                                    <input type="hidden" name="return_bill_code_id" value="<?= $billCodeId ?>">
                                    <input type="hidden" name="source_type" value="<?= wbr_e($row['source_type']) ?>">
                                    <input type="hidden" name="source_id" value="<?= (int)$row['source_id'] ?>">
                                    <input class="reason-input" type="text" name="reason" placeholder="Reason / remarks">
                                    <button type="submit" class="btn btn-danger">Disconnect Customer</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </section>

    <?php if ($alreadyDisconnectedCandidates): ?>
    <section class="card">
        <div class="card-head">
            <h2 class="card-title">Flagged Bills from Customers Already Disconnected <span class="count-pill"><?= count($alreadyDisconnectedCandidates) ?></span></h2>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Customer</th>
                        <th>Bill</th>
                        <th>Due / Disconnection</th>
                        <th>Balance</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($alreadyDisconnectedCandidates as $row): ?>
                    <tr>
                        <td>
                            <strong><?= wbr_e($row['member_name']) ?></strong><br>
                            <span class="small muted">Account #: <?= wbr_e($row['account_number'] ?: '—') ?></span>
                        </td>
                        <td>
                            <span class="badge <?= mdh_badge_class((string)$row['source_type']) ?>"><?= wbr_e($row['bill_type']) ?></span><br>
                            <?= wbr_e($row['bill_label']) ?><br>
                            <span class="small muted">Bill Code: <?= wbr_e($row['bill_code'] ?? '—') ?></span>
                        </td>
                        <td><?= mdh_date($row['due_date']) ?> / <?= mdh_date($row['disconnection_date']) ?></td>
                        <td class="amount">₱<?= mdh_money($row['balance']) ?></td>
                        <td><span class="status-disconnected">Disconnected</span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endif; ?>

    <section class="card">
        <div class="card-head">
            <h2 class="card-title">Disconnected Customers <span class="count-pill"><?= count($disconnectedMembers) ?></span></h2>
        </div>
        <div class="table-wrap">
            <?php if (!$disconnectedMembers): ?>
                <div class="empty">No customers are currently marked as disconnected.</div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th>Disconnected At</th>
                            <th>Disconnected By</th>
                            <th>Reason</th>
                            <th class="action-col">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($disconnectedMembers as $row): ?>
                        <tr>
                            <td>
                                <strong><?= wbr_e($row['member_name']) ?></strong><br>
                                <span class="small muted">Account #: <?= wbr_e($row['account_number'] ?: '—') ?></span><br>
                                <span class="status-disconnected">Disconnected</span>
                            </td>
                            <td><?= wbr_e($row['disconnected_at'] ? date('m/d/Y h:i A', strtotime((string)$row['disconnected_at'])) : '—') ?></td>
                            <td><?= wbr_e($row['disconnected_by_name'] ?: ($row['disconnected_by_username'] ?: '—')) ?></td>
                            <td><?= wbr_e($row['disconnection_reason'] ?: '—') ?></td>
                            <td class="action-col">
                                <form method="post" action="../process/updateManualDisconnection.php" data-confirm="Mark this customer as active/reconnected?">
                                    <input type="hidden" name="csrf_token" value="<?= wbr_e(csrfToken()) ?>">
                                    <input type="hidden" name="action" value="reconnect">
                                    <input type="hidden" name="return_q" value="<?= wbr_e($search) ?>">
                                    <input type="hidden" name="return_bill_code_id" value="<?= $billCodeId ?>">
                                    <input type="hidden" name="member_id" value="<?= (int)$row['member_id'] ?>">
                                    <input class="reason-input" type="text" name="reason" placeholder="Reconnection remarks">
                                    <button type="submit" class="btn btn-success">Reconnect / Mark Active</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </section>
    </div>
</div>
</div>
</body>
</html>
