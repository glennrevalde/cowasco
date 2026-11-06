<?php
require_once __DIR__ . '/../database/config.php';

require_once '../database/config.php';
require_once '../process/accessControlHelpers.php';
require_once '../common/customerLedgerHelpers.php';

$pdo = getDB();
require_once __DIR__ . '/../common/institutionConfigurationHelpers.php';
$ledgerIssuer = institution_issuer($pdo);

if (empty($_SESSION['user_id']) || empty($_SESSION['full_name'])) {
    header('Location: login.php');
    exit;
}

/*
 * The Member Ledger is reachable from Reports Management, Billing History,
 * and Collection History. Any one of those parent permissions is sufficient.
 */
if (
    !access_is_superadmin()
    && !userCanAccessPage($pdo, 'reports_management')
    && !userCanAccessPage($pdo, 'billing_history')
    && !userCanAccessPage($pdo, 'payment_history')
) {
    $_SESSION['error_msg'] = 'Access denied. You do not have permission to generate member ledgers.';
    header('Location: dashboard.php');
    exit;
}

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

function ml_money($value): string
{
    return number_format((float)($value ?? 0), 2);
}

function ml_date(?string $date): string
{
    if (!$date || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') {
        return '';
    }
    $time = strtotime($date);
    return $time ? date('m/d/Y', $time) : '';
}

function ml_period_option_label(array $period): string
{
    $code = trim((string)($period['bp_code'] ?? ''));
    $from = ml_date((string)($period['start_date'] ?? ''));
    $to = ml_date((string)($period['end_date'] ?? ''));
    $range = trim($from . ($from !== '' && $to !== '' ? ' - ' : '') . $to);
    return trim(($code !== '' ? $code . ' | ' : '') . $range);
}

function ml_member_option_label(array $member): string
{
    $name = trim(implode(' ', array_filter([
        trim((string)($member['lastname'] ?? '')),
        trim((string)($member['firstname'] ?? '')),
        trim((string)($member['middlename'] ?? '')),
    ], static fn(string $value): bool => $value !== '')));
    $account = trim((string)($member['account_number'] ?? ''));
    return ($account !== '' ? $account . ' — ' : '') . ($name !== '' ? $name : ('Member #' . (int)$member['pkey']));
}

$memberId = max(0, (int)($_GET['member_id'] ?? 0));
$fromPeriodId = max(0, (int)($_GET['period_from'] ?? 0));
$toPeriodId = max(0, (int)($_GET['period_to'] ?? 0));

$codeInfo = cl_report_bill_codes($pdo);
$billCodes = $codeInfo['codes'];
$waterBillCodeId = (int)$codeInfo['water_bill_code_id'];
$periods = cl_report_periods($pdo);
$members = cl_report_members($pdo);

$hasSubmittedBillCodes = array_key_exists('bill_codes', $_GET);
$requestedBillCodeIds = $hasSubmittedBillCodes && is_array($_GET['bill_codes'])
    ? array_values(array_unique(array_map('intval', $_GET['bill_codes'])))
    : array_map('intval', array_keys($billCodes));

if ($waterBillCodeId > 0 && !in_array($waterBillCodeId, $requestedBillCodeIds, true)) {
    array_unshift($requestedBillCodeIds, $waterBillCodeId);
}

$error = '';
$report = [
    'member' => null,
    'bill_codes' => $billCodes,
    'water_bill_code_id' => $waterBillCodeId,
    'selected_bill_code_ids' => $requestedBillCodeIds,
    'periods' => $periods,
    'selected_periods' => [],
    'rows' => [],
    'totals' => [
        'charges' => [],
        'penalty' => 0,
        'compromise' => 0,
        'other_fees' => 0,
        'total_amount' => 0,
    ],
];

if ($memberId > 0) {
    try {
        $report = cl_report_fetch_member_ledger(
            $pdo,
            $memberId,
            $fromPeriodId,
            $toPeriodId,
            $requestedBillCodeIds
        );
        if (!$report['member']) {
            $error = 'The selected member was not found.';
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$member = $report['member'];
$rows = $report['rows'];
$totals = $report['totals'];
$selectedCodeIds = array_map('intval', $report['selected_bill_code_ids'] ?? $requestedBillCodeIds);
$selectedNonWaterCodes = [];
foreach ($selectedCodeIds as $codeId) {
    if ($codeId === $waterBillCodeId || !isset($billCodes[$codeId])) {
        continue;
    }
    $selectedNonWaterCodes[$codeId] = $billCodes[$codeId];
}

$periodLabel = 'All Billing Periods';
if (!empty($report['selected_periods'])) {
    $firstPeriod = $report['selected_periods'][0];
    $lastPeriod = $report['selected_periods'][count($report['selected_periods']) - 1];
    if ((int)$firstPeriod['period_id'] === (int)$lastPeriod['period_id']) {
        $periodLabel = ml_period_option_label($firstPeriod);
    } else {
        $periodLabel = ml_date((string)$firstPeriod['start_date']) . ' - ' . ml_date((string)$lastPeriod['end_date']);
    }
}

$generatedBy = trim((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'System User'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Member Ledger<?= $member ? ' — ' . e($member['display_name']) : '' ?></title>
<link rel="stylesheet" href="../common/fonts.css">
<style>
* { box-sizing: border-box; }
:root {
    --bg: #eef2f7;
    --paper: #ffffff;
    --ink: #111827;
    --muted: #667085;
    --line: #111827;
    --soft-line: #d0d5dd;
    --accent: #17233c;
    --blue: #155eef;
    --danger: #b42318;
}
body {
    margin: 0;
    min-height: 100vh;
    background: var(--bg);
    color: var(--ink);
    font-family: 'Sora', Arial, sans-serif;
}
.screen-wrap {
    max-width: 1720px;
    margin: 0 auto;
    padding: 22px;
}
.toolbar,
.filter-panel,
.notice {
    background: #fff;
    border: 1px solid #d0d5dd;
    border-radius: 12px;
    box-shadow: 0 6px 22px rgba(16,24,40,.05);
}
.toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    padding: 12px 14px;
    margin-bottom: 12px;
}
.toolbar-group { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.btn {
    appearance: none;
    border: 1px solid #cbd5e1;
    border-radius: 9px;
    background: #fff;
    color: #17233c;
    padding: 9px 13px;
    font: 700 .82rem 'Sora', Arial, sans-serif;
    text-decoration: none;
    cursor: pointer;
}
.btn:hover { border-color: #98a2b3; }
.btn.primary { background: var(--accent); color: #fff; border-color: var(--accent); }
.filter-panel { padding: 18px; margin-bottom: 16px; }
.filter-title { margin: 0 0 4px; font-size: 1rem; }
.filter-copy { margin: 0 0 16px; color: var(--muted); font-size: .8rem; }
.filter-grid {
    display: grid;
    grid-template-columns: minmax(270px, 1.5fr) minmax(190px, 1fr) minmax(190px, 1fr);
    gap: 12px;
}
.form-group label,
.bill-code-heading {
    display: block;
    margin-bottom: 6px;
    color: #344054;
    font-size: .72rem;
    font-weight: 700;
}
.form-input {
    width: 100%;
    min-height: 42px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    background: #fff;
    padding: 9px 10px;
    font: 500 .82rem 'Sora', Arial, sans-serif;
    color: #101828;
}
.member-search { margin-bottom: 7px; }
.bill-code-block { margin-top: 16px; border-top: 1px solid #eaecf0; padding-top: 14px; }
.bill-code-list {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 8px;
}
.bill-code-option {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    min-height: 42px;
    border: 1px solid #e4e7ec;
    border-radius: 8px;
    padding: 9px 10px;
    background: #fcfcfd;
}
.bill-code-option.required { border-color: #9db7ff; background: #f5f8ff; }
.bill-code-option input { margin-top: 2px; }
.bill-code-name { display: block; font-size: .77rem; font-weight: 700; }
.bill-code-desc { display: block; margin-top: 2px; color: var(--muted); font-size: .66rem; line-height: 1.35; }
.required-badge { color: #175cd3; font-size: .62rem; font-weight: 800; margin-left: 5px; }
.filter-actions { display: flex; gap: 8px; margin-top: 16px; flex-wrap: wrap; }
.notice { padding: 16px 18px; color: var(--muted); font-size: .85rem; line-height: 1.5; }
.notice.error { color: var(--danger); border-color: #fecdca; background: #fffbfa; }
.ledger-sheet {
    background: var(--paper);
    border: 1px solid #b7bec8;
    box-shadow: 0 10px 34px rgba(16,24,40,.08);
    padding: 18mm 9mm 13mm;
    overflow-x: auto;
}
.ledger-document { min-width: 1260px; }
.report-header { text-align: center; line-height: 1.25; margin-bottom: 12px; }
.report-header .republic { font-size: .78rem; font-style: italic; }
.report-header .cooperative { font-size: .92rem; font-weight: 700; text-transform: uppercase; }
.report-header .address { font-size: .82rem; }
.report-header .title { margin-top: 5px; font-size: .95rem; font-weight: 800; letter-spacing: .05em; }
.report-period { margin-top: 3px; font-size: .68rem; color: #475467; }
.member-info {
    display: grid;
    grid-template-columns: 1fr 1fr;
    column-gap: 11%;
    row-gap: 4px;
    margin: 10px 7% 13px;
    font-size: .74rem;
}
.info-line { display: grid; grid-template-columns: 125px 1fr; gap: 8px; align-items: end; }
.info-label { font-weight: 600; }
.info-value { min-height: 18px; border-bottom: 1px solid #111827; padding: 0 4px 2px; font-weight: 600; }
.ledger-table { width: 100%; border-collapse: collapse; table-layout: fixed; font-family: Arial, Helvetica, sans-serif; font-size: 7.2pt; }
.ledger-table th,
.ledger-table td { border: 1px solid var(--line); padding: 3px 3px; text-align: center; vertical-align: middle; }
.ledger-table th { font-weight: 700; line-height: 1.12; background: #fff; overflow-wrap: anywhere; }
.ledger-table td { height: 22px; }
.ledger-table .text-cell { text-align: left; overflow-wrap: anywhere; }
.ledger-table .money { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.ledger-table .reading { font-variant-numeric: tabular-nums; }
.ledger-table tfoot td { font-weight: 700; border-top-width: 2px; }
.total-label { text-align: right !important; }
.grand-total-box {
    display: inline-block;
    min-width: 105px;
    border: 1.5px solid #111827;
    padding: 3px 9px;
    text-align: right;
    font-weight: 700;
}
.report-footer {
    display: flex;
    justify-content: space-between;
    gap: 20px;
    margin-top: 18px;
    color: #475467;
    font-size: .62rem;
}
@media (max-width: 900px) {
    .screen-wrap { padding: 10px; }
    .filter-grid { grid-template-columns: 1fr; }
    .ledger-sheet { padding: 20px 12px; }
}
@media print {
    @page { size: A4 landscape; margin: 5mm; }
    body { background: #fff !important; }
    .screen-wrap { max-width: none; margin: 0; padding: 0; }
    .screen-only { display: none !important; }
    .ledger-sheet { border: 0; box-shadow: none; padding: 0; overflow: visible; }
    .ledger-document { min-width: 0; width: 100%; }
    .report-header { margin-bottom: 7px; }
    .member-info { margin-top: 6px; margin-bottom: 8px; }
    .ledger-table { font-size: 6.2pt; }
    .ledger-table th, .ledger-table td { padding: 2px 2px; }
    .ledger-table td { height: 18px; }
    thead { display: table-header-group; }
    tfoot { display: table-footer-group; }
    tr { break-inside: avoid; page-break-inside: avoid; }
    .report-footer { margin-top: 8px; }
}
</style>
<link rel="stylesheet" href="../common/ui-consistency.css">
<script src="../common/business-ui-consistency.js" defer></script>
</head>
<body>
<div class="screen-wrap">
    <div class="toolbar screen-only">
        <div class="toolbar-group">
            <a class="btn" href="reports_management.php">← Reports Management</a>
            <a class="btn" href="billing_history.php">Billing History</a>
            <a class="btn" href="payment_history.php">Collection History</a>
        </div>
        <div class="toolbar-group">
            <?php if ($member && !$error): ?>
                <button class="btn primary" type="button" onclick="window.print()">Print Member Ledger</button>
            <?php endif; ?>
        </div>
    </div>

    <form class="filter-panel screen-only" method="get" action="customer_ledger.php" id="ledgerFilterForm">
        <h1 class="filter-title">Generate Member Ledger</h1>
        <p class="filter-copy">Select a member, billing-period range, and bill-code columns. Water Bill is always included. Charges from unchecked bill codes are grouped under Other Fees.</p>

        <div class="filter-grid">
            <div class="form-group">
                <label for="memberSearch">Member</label>
                <input class="form-input member-search" id="memberSearch" type="search" placeholder="Search account number or member name" autocomplete="off">
                <select class="form-input" id="memberId" name="member_id" required>
                    <option value="">Select member</option>
                    <?php foreach ($members as $memberOption): ?>
                        <option
                            value="<?= (int)$memberOption['pkey'] ?>"
                            data-search="<?= e(strtolower(ml_member_option_label($memberOption))) ?>"
                            <?= (int)$memberOption['pkey'] === $memberId ? 'selected' : '' ?>
                        ><?= e(ml_member_option_label($memberOption)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="periodFrom">From Billing Period</label>
                <select class="form-input" id="periodFrom" name="period_from">
                    <option value="0">First available period</option>
                    <?php foreach ($periods as $period): ?>
                        <option value="<?= (int)$period['period_id'] ?>" <?= (int)$period['period_id'] === $fromPeriodId ? 'selected' : '' ?>><?= e(ml_period_option_label($period)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="periodTo">To Billing Period</label>
                <select class="form-input" id="periodTo" name="period_to">
                    <option value="0">Latest available period</option>
                    <?php foreach ($periods as $period): ?>
                        <option value="<?= (int)$period['period_id'] ?>" <?= (int)$period['period_id'] === $toPeriodId ? 'selected' : '' ?>><?= e(ml_period_option_label($period)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="bill-code-block">
            <span class="bill-code-heading">Bill Codes to Display as Separate Columns</span>
            <div class="bill-code-list">
                <?php if ($waterBillCodeId > 0): ?>
                    <input type="hidden" name="bill_codes[]" value="<?= $waterBillCodeId ?>">
                <?php endif; ?>
                <label class="bill-code-option required">
                    <input type="checkbox" checked disabled aria-label="Water Bill is required">
                    <span>
                        <span class="bill-code-name">WATER BILL <span class="required-badge">REQUIRED</span></span>
                        <span class="bill-code-desc">Water reading, consumption, and water-bill amount are always printed.</span>
                    </span>
                </label>
                <?php foreach ($billCodes as $codeId => $billCode): ?>
                    <?php if ((int)$codeId === $waterBillCodeId) { continue; } ?>
                    <label class="bill-code-option">
                        <input type="checkbox" name="bill_codes[]" value="<?= (int)$codeId ?>" <?= in_array((int)$codeId, $selectedCodeIds, true) ? 'checked' : '' ?>>
                        <span>
                            <span class="bill-code-name"><?= e($billCode['code']) ?></span>
                            <span class="bill-code-desc"><?= e($billCode['description'] !== '' ? $billCode['description'] : ($billCode['type'] ?: 'Additional bill code')) ?></span>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="filter-actions">
            <button class="btn primary" type="submit">Generate Ledger</button>
            <?php if ($memberId > 0): ?><a class="btn" href="customer_ledger.php?member_id=<?= $memberId ?>">Reset Periods and Bill Codes</a><?php endif; ?>
        </div>
    </form>

    <?php if ($error): ?>
        <div class="notice error screen-only"><?= e($error) ?></div>
    <?php elseif (!$member): ?>
        <div class="notice screen-only">Select a member and click Generate Ledger. The report will include only periods containing a posted bill, another bill-code charge, or an active payment transaction.</div>
    <?php else: ?>
        <section class="ledger-sheet">
            <div class="ledger-document">
                <header class="report-header">
                    <div class="republic">Republic of the Philippines</div>
                    <div class="cooperative"><?= htmlspecialchars($ledgerIssuer['name'], ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="address"><?= htmlspecialchars($ledgerIssuer['address'], ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="title">LEDGER</div>
                    <div class="report-period">Billing Period: <?= e($periodLabel) ?></div>
                </header>

                <section class="member-info">
                    <div class="info-line"><span class="info-label">Account Name:</span><span class="info-value"><?= e($member['display_name'] ?: '—') ?></span></div>
                    <div class="info-line"><span class="info-label">Account Number:</span><span class="info-value"><?= e($member['account_number'] ?: $member['pkey']) ?></span></div>
                    <div class="info-line"><span class="info-label">Account Address:</span><span class="info-value"><?= e($member['address'] ?: '—') ?></span></div>
                    <div class="info-line"><span class="info-label">Sequence Number:</span><span class="info-value"><?= e(trim((string)($member['meter_sequence'] ?? '')) !== '' ? $member['meter_sequence'] : '—') ?></span></div>
                    <div class="info-line"><span class="info-label">Rate Code:</span><span class="info-value"><?= e(trim((string)($member['rc_code'] ?? '')) !== '' ? $member['rc_code'] : '—') ?></span></div>
                    <div class="info-line"><span class="info-label">Zone Number:</span><span class="info-value"><?= e(($member['zone'] ?? '') !== '' && $member['zone'] !== null ? $member['zone'] : '—') ?></span></div>
                </section>

                <?php if (!$rows): ?>
                    <div class="notice">No posted bills or active collection transactions were found for the selected member and billing-period range.</div>
                <?php else: ?>
                    <table class="ledger-table">
                        <thead>
                            <tr>
                                <th style="width:32px">No.</th>
                                <th style="width:82px">Receipt<br>Number</th>
                                <th style="width:65px">Date Paid</th>
                                <th style="width:64px">Pres.<br>Reading</th>
                                <th style="width:64px">Prev.<br>Reading</th>
                                <th style="width:60px">Present<br>Reading</th>
                                <th style="width:60px">Previous<br>Reading</th>
                                <th style="width:45px">Usage</th>
                                <th style="width:68px">Water<br>Bill</th>
                                <th style="width:52px">Penalty</th>
                                <th style="width:52px">Comp.<br>Amt.</th>
                                <?php foreach ($selectedNonWaterCodes as $billCode): ?>
                                    <th style="width:62px"><?= e($billCode['code']) ?></th>
                                <?php endforeach; ?>
                                <th style="width:58px">Other<br>Fees</th>
                                <th style="width:72px">Total<br>Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $index => $row): ?>
                                <tr>
                                    <td><?= $index + 1 ?></td>
                                    <td class="text-cell"><?= e($row['receipt_number'] !== '' ? $row['receipt_number'] : '') ?></td>
                                    <td><?= e($row['date_paid'] !== '' ? implode(' / ', array_map('ml_date', explode(' / ', $row['date_paid']))) : '') ?></td>
                                    <td><?= e(ml_date((string)$row['present_reading_date'])) ?></td>
                                    <td><?= e(ml_date((string)$row['previous_reading_date'])) ?></td>
                                    <td class="reading"><?= $row['present_reading'] !== null ? number_format((int)$row['present_reading']) : '' ?></td>
                                    <td class="reading"><?= $row['previous_reading'] !== null ? number_format((int)$row['previous_reading']) : '' ?></td>
                                    <td class="reading"><?= $row['usage'] !== null ? number_format((int)$row['usage']) : '' ?></td>
                                    <td class="money"><?= ml_money($row['charges'][$waterBillCodeId] ?? 0) ?></td>
                                    <td class="money"><?= ml_money($row['penalty']) ?></td>
                                    <td class="money"><?= ml_money($row['compromise']) ?></td>
                                    <?php foreach ($selectedNonWaterCodes as $codeId => $billCode): ?>
                                        <td class="money"><?= ml_money($row['charges'][$codeId] ?? 0) ?></td>
                                    <?php endforeach; ?>
                                    <td class="money"><?= ml_money($row['other_fees']) ?></td>
                                    <td class="money"><?= ml_money($row['total_amount']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <?php $fixedLeadingColumns = 8; ?>
                                <td class="total-label" colspan="<?= $fixedLeadingColumns ?>">TOTAL:</td>
                                <td class="money"><?= ml_money($totals['charges'][$waterBillCodeId] ?? 0) ?></td>
                                <td class="money"><?= ml_money($totals['penalty']) ?></td>
                                <td class="money"><?= ml_money($totals['compromise']) ?></td>
                                <?php foreach ($selectedNonWaterCodes as $codeId => $billCode): ?>
                                    <td class="money"><?= ml_money($totals['charges'][$codeId] ?? 0) ?></td>
                                <?php endforeach; ?>
                                <td class="money"><?= ml_money($totals['other_fees']) ?></td>
                                <td class="money"><span class="grand-total-box"><?= ml_money($totals['total_amount']) ?></span></td>
                            </tr>
                        </tfoot>
                    </table>
                <?php endif; ?>

                <footer class="report-footer">
                    <div>Generated <?= e(date('l, F j, Y')) ?> by <?= e($generatedBy) ?></div>
                    <div>Water Bill is mandatory. Unchecked bill codes are consolidated under Other Fees.</div>
                </footer>
            </div>
        </section>
    <?php endif; ?>
</div>
<script>
(function () {
    const search = document.getElementById('memberSearch');
    const select = document.getElementById('memberId');
    if (!search || !select) return;

    const originalOptions = Array.from(select.options).map(option => ({
        value: option.value,
        text: option.textContent,
        search: option.dataset.search || option.textContent.toLowerCase(),
        selected: option.selected
    }));

    // incremental search (version 46): every word typed must be found (in any order); the list narrows with each
    // letter and the first match is chosen, so Enter generates that member's ledger at once
    const fold = t => String(t || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
    search.addEventListener('input', function () {
        const words = fold(this.value).split(/[\s,;]+/).filter(Boolean);
        const currentValue = select.value;
        select.innerHTML = '';
        let first = null;
        let keep = false;

        originalOptions.forEach(item => {
            const hay = fold(item.search + ' ' + item.text);
            if (item.value === '' || !words.length || words.every(w => hay.includes(w))) {
                const option = new Option(item.text, item.value, false, item.value === currentValue);
                option.dataset.search = item.search;
                select.add(option);
                if (item.value !== '' && first === null) first = item.value;
                if (item.value === currentValue && item.value !== '') keep = true;
            }
        });
        if (words.length && first !== null && !keep) select.value = first;
        if (words.length && first === null) select.value = '';
    });
})();
</script>
</body>
</html>
