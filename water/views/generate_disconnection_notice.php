<?php
/* AUTH_GUARD_START */
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../process/accessControlHelpers.php';
$__authGuardPdo = getDB();
requirePageAccess($__authGuardPdo, basename(__FILE__, '.php'));
unset($__authGuardPdo);
/* AUTH_GUARD_END */

require_once __DIR__ . '/../process/disconnectionNoticeHelpers.php';
$institutionHelper = __DIR__ . '/../common/institutionConfigurationHelpers.php';
if (is_file($institutionHelper)) {
    require_once $institutionHelper;
}

$pdo = getDB();
// the cooperative issues the notice (company settings, then the institution profile)
$noticeIssuer = function_exists('institution_issuer') ? institution_issuer($pdo) : ['name' => '', 'address' => '', 'phone' => '', 'tin' => ''];

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('dnh_date_display')) {
    function dnh_date_display($value, string $fallback = '—'): string
    {
        $value = trim((string)($value ?? ''));
        if ($value === '') {
            return $fallback;
        }
        $timestamp = strtotime($value);
        return $timestamp === false ? $fallback : date('m/d/Y', $timestamp);
    }
}

if (!function_exists('dnh_query_url')) {
    function dnh_query_url(array $params): string
    {
        return 'generate_disconnection_notice.php?' . http_build_query(
            array_filter($params, static fn($value): bool => $value !== null && $value !== '')
        );
    }
}

$mode = strtolower(trim((string)($_GET['mode'] ?? 'batch')));
if (!in_array($mode, ['batch', 'single'], true)) {
    $mode = 'batch';
}

$search = trim((string)($_GET['q'] ?? ''));
$month = trim((string)($_GET['month'] ?? ''));
if ($month !== '' && !preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = '';
}
$zone = max(0, (int)($_GET['zone'] ?? 0));
$memberId = max(0, (int)($_GET['member_id'] ?? 0));

$managerName = '';
$generalManagerName = '';
if (function_exists('institution_officer_for_report')) {
    $manager = institution_officer_for_report($pdo, 'manager');
    $generalManager = institution_officer_for_report($pdo, 'general_manager');
    $managerName = trim((string)($manager['name'] ?? ''));
    $generalManagerName = trim((string)($generalManager['name'] ?? ''));
}

$noticeFrom = trim((string)($_GET['notice_from'] ?? ''));
if ($noticeFrom === '') {
    $noticeFrom = $generalManagerName !== '' ? $generalManagerName : 'The General Manager';
}
$noticeToInput = trim((string)($_GET['notice_to'] ?? ''));
$noticeDate = trim((string)($_GET['notice_date'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $noticeDate)) {
    $noticeDate = date('Y-m-d');
}
$memoNumber = trim((string)($_GET['memo_no'] ?? ''));
$seriesYear = trim((string)($_GET['series_year'] ?? '2002'));
if ($seriesYear === '') {
    $seriesYear = '2002';
}

$defaultBatchRemarks = 'You are hereby directed to remind the member/consumer listed below to settle their obligation to the cooperative office within one week or their water service will be disconnected in violation of the operating policy/contract of the cooperative.';
$defaultSingleRemarks = 'You are hereby notified to settle the outstanding obligation detailed below at the cooperative office within one week. Failure to settle the account may result in the disconnection of the water service in accordance with the operating policy/contract of the cooperative.';
$remarks = trim((string)($_GET['remarks'] ?? ($mode === 'single' ? $defaultSingleRemarks : $defaultBatchRemarks)));
if ($remarks === '') {
    $remarks = $mode === 'single' ? $defaultSingleRemarks : $defaultBatchRemarks;
}

$billCodes = [];
$allRows = [];
$scopeRows = [];
$filteredRows = [];
$dataError = null;
try {
    $billCodes = dnh_fetch_bill_codes($pdo);
    $allRows = dnh_fetch_unpaid_bills($pdo);

    $scopeRows = array_values(array_filter($allRows, static function (array $row) use ($zone, $search): bool {
        if ($zone > 0 && (int)($row['zone'] ?? 0) !== $zone) {
            return false;
        }
        if ($search === '') {
            return true;
        }
        $haystack = implode(' ', [
            (string)($row['account_number'] ?? ''),
            (string)($row['member_id'] ?? ''),
            (string)($row['member_name'] ?? ''),
            (string)($row['bill_code'] ?? ''),
            (string)($row['bill_code_description'] ?? ''),
            (string)($row['period_label'] ?? ''),
        ]);
        return stripos($haystack, $search) !== false;
    }));

    $availableMonths = [];
    foreach ($scopeRows as $row) {
        $rowMonth = trim((string)($row['period_month'] ?? ''));
        if ($rowMonth !== '') {
            $availableMonths[$rowMonth] = true;
        }
    }
    $availableMonths = array_keys($availableMonths);
    rsort($availableMonths, SORT_STRING);

    // The supplied batch form is explicitly for one period. Default to the
    // latest unpaid period so a valid period is always indicated on the notice.
    if ($month === '' && $availableMonths) {
        $month = $availableMonths[0];
    }

    $filteredRows = array_values(array_filter(
        $scopeRows,
        static fn(array $row): bool => $month === '' || (string)($row['period_month'] ?? '') === $month
    ));
} catch (Throwable $error) {
    error_log('Disconnection notice load error: ' . $error->getMessage());
    $dataError = 'The unpaid-bill register could not be loaded. No balance was assumed.';
    $availableMonths = [];
}

$zones = [];
foreach ($allRows as $row) {
    $rowZone = max(0, (int)($row['zone'] ?? 0));
    if ($rowZone > 0) {
        $zones[$rowZone] = true;
    }
}
$zones = array_keys($zones);
sort($zones, SORT_NUMERIC);

$codesSubmitted = isset($_GET['codes_submitted']);
if ($codesSubmitted) {
    $selectedBillCodeIds = [];
    foreach ((array)($_GET['bill_codes'] ?? []) as $rawId) {
        if (is_scalar($rawId) && preg_match('/^-?\d+$/', (string)$rawId)) {
            $id = (int)$rawId;
            if ($id >= 0 && !in_array($id, $selectedBillCodeIds, true)) {
                $selectedBillCodeIds[] = $id;
            }
        }
    }
} else {
    $selectedBillCodeIds = dnh_default_selected_bill_code_ids($billCodes, 3);
}

$selectedBillCodes = [];
foreach ($billCodes as $code) {
    if (in_array((int)($code['code_id'] ?? 0), $selectedBillCodeIds, true)) {
        $selectedBillCodes[] = $code;
    }
}

$memberOptions = [];
foreach ($filteredRows as $row) {
    $id = (int)($row['member_id'] ?? 0);
    if ($id <= 0 || isset($memberOptions[$id])) {
        continue;
    }
    $memberOptions[$id] = [
        'member_id' => $id,
        'account_number' => (string)($row['account_number'] ?? ''),
        'member_name' => (string)($row['member_name'] ?? ''),
        'zone' => (int)($row['zone'] ?? 0),
        'meter_sequence' => (string)($row['meter_sequence'] ?? ''),
    ];
}
usort($memberOptions, static function (array $a, array $b): int {
    $zoneCompare = ((int)$a['zone']) <=> ((int)$b['zone']);
    return $zoneCompare !== 0 ? $zoneCompare : strcasecmp((string)$a['member_name'], (string)$b['member_name']);
});

if ($mode === 'single' && $memberId <= 0 && $memberOptions) {
    $memberId = (int)$memberOptions[0]['member_id'];
}

$singleRows = [];
$memberDetails = null;
if ($mode === 'single' && $memberId > 0) {
    $singleRows = array_values(array_filter(
        $filteredRows,
        static fn(array $row): bool => (int)($row['member_id'] ?? 0) === $memberId
    ));
    try {
        $memberDetails = dnh_fetch_member_details($pdo, $memberId);
    } catch (Throwable $error) {
        error_log('Disconnection member detail load error: ' . $error->getMessage());
    }
}

$noticeTo = $noticeToInput;
if ($mode === 'single' && $noticeTo === '' && $memberDetails) {
    $noticeTo = (string)$memberDetails['member_name'];
}

$batchRows = dnh_group_batch_rows($filteredRows, $selectedBillCodeIds);
$periodCaption = $month !== '' ? date('F Y', strtotime($month . '-01')) : 'No period selected';
$zoneCaption = $zone > 0 ? 'ZONE ' . $zone : 'ALL ZONES';

$batchPrincipalTotal = array_sum(array_map(
    static fn(array $row): float => (float)($row['principal_total'] ?? 0),
    $batchRows
));
$singlePrincipalTotal = array_sum(array_map(
    static fn(array $row): float => (float)($row['principal_balance'] ?? 0),
    $singleRows
));

$earliestDueDate = null;
$earliestDisconnectionDate = null;
foreach ($singleRows as $row) {
    $dueDate = trim((string)($row['due_date'] ?? ''));
    if ($dueDate !== '' && ($earliestDueDate === null || $dueDate < $earliestDueDate)) {
        $earliestDueDate = $dueDate;
    }
    $disconnectDate = trim((string)($row['disconnection_date'] ?? ''));
    if ($disconnectDate !== '' && ($earliestDisconnectionDate === null || $disconnectDate < $earliestDisconnectionDate)) {
        $earliestDisconnectionDate = $disconnectDate;
    }
}

$batchTabUrl = dnh_query_url([
    'mode' => 'batch',
    'month' => $month,
    'zone' => $zone ?: null,
]);
$singleTabUrl = dnh_query_url([
    'mode' => 'single',
    'month' => $month,
    'zone' => $zone ?: null,
    'member_id' => $memberId ?: null,
]);
$resetUrl = 'generate_disconnection_notice.php?mode=' . $mode;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Generate Disconnection Notice</title>
<style>
*{box-sizing:border-box}html,body{margin:0;min-height:100%;font-family:Arial,Helvetica,sans-serif;color:#111827}body{background:#f5f7fa}.page-shell{max-width:1500px;margin:0 auto;padding:26px 28px 70px}.screen-header{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:16px}.screen-header h1{margin:0 0 5px;font-size:25px}.screen-sub{color:#667085;font-size:14px;line-height:1.5}.actions{display:flex;gap:8px;flex-wrap:wrap}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:40px;padding:9px 13px;border:1px solid #101828;border-radius:7px;background:#101828;color:#fff;font-weight:800;font-size:13px;text-decoration:none;cursor:pointer}.btn.light{background:#fff;color:#101828;border-color:#d0d5dd}.btn.primary{background:#155eef;border-color:#155eef}.tabs{display:flex;gap:7px;margin-bottom:14px}.tab{padding:10px 14px;border:1px solid #d0d5dd;border-radius:8px;background:#fff;color:#344054;font-size:13px;font-weight:800;text-decoration:none}.tab.active{background:#101828;border-color:#101828;color:#fff}.card{background:#fff;border:1px solid #e4e7ec;border-radius:11px;margin-bottom:15px}.card-body{padding:16px}.notice-message{padding:12px 14px;border-radius:8px;margin-bottom:14px;font-size:13px;line-height:1.5}.notice-message.info{background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8}.notice-message.error{background:#fff1f3;border:1px solid #fecdd3;color:#be123c}.filter-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.field.full{grid-column:1/-1}.field.span-2{grid-column:span 2}.field label{display:block;margin:0 0 5px;color:#475467;font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.04em}.field input,.field select,.field textarea{width:100%;padding:9px 10px;border:1px solid #d0d5dd;border-radius:7px;background:#fff;color:#101828;font:inherit;font-size:13px}.field textarea{min-height:78px;resize:vertical}.bill-code-box{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:7px;padding:10px;border:1px solid #e4e7ec;border-radius:8px;background:#f9fafb}.bill-code-option{display:flex;gap:8px;align-items:flex-start;padding:8px;border:1px solid #eaecf0;border-radius:7px;background:#fff;font-size:12px}.bill-code-option input{width:auto;margin-top:2px}.bill-code-option strong{display:block}.bill-code-option small{display:block;margin-top:2px;color:#667085;line-height:1.3}.form-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:13px}.stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:15px}.stat{padding:13px 14px;border:1px solid #e4e7ec;border-radius:9px;background:#fff}.stat-label{font-size:10px;font-weight:900;text-transform:uppercase;color:#667085}.stat-value{margin-top:4px;font-size:21px;font-weight:900}.paper-wrap{overflow:auto;padding:18px;background:#dfe3e8;border:1px solid #d0d5dd;border-radius:10px}.print-sheet{width:210mm;min-height:297mm;margin:0 auto;padding:13mm 11mm 15mm;background:#fff;box-shadow:0 3px 18px rgba(16,24,40,.14);font-family:Arial,Helvetica,sans-serif;color:#000}.document-header{text-align:center;font-weight:800;line-height:1.25}.document-header .line-1{font-size:14px}.document-header .line-2{font-size:17px}.document-header .line-3{font-size:14px}.memo-zone{text-align:center;font-size:16px;font-weight:900;margin:14px 0 -18px}.memo-block{display:grid;grid-template-columns:145px 14px 1fr;row-gap:4px;margin-top:13px;font-size:12px;line-height:1.35}.memo-block .label{font-weight:900}.memo-block .value{font-weight:700}.memo-block .remarks-value{font-weight:600;text-align:justify}.customer-detail-grid{display:grid;grid-template-columns:145px 1fr 145px 1fr;gap:5px 12px;margin:16px 0 10px;padding:10px;border:1px solid #000;font-size:10px}.customer-detail-grid .label{font-weight:900}.print-table{width:100%;border-collapse:collapse;table-layout:fixed;margin-top:14px;font-size:8.5px}.print-table th,.print-table td{border:1px solid #000;padding:5px 4px;vertical-align:middle;overflow-wrap:anywhere}.print-table th{text-align:center;font-weight:900;line-height:1.1}.print-table td.num{text-align:right;font-variant-numeric:tabular-nums}.print-table td.center{text-align:center}.print-table .concessionaire{text-transform:uppercase}.print-table tfoot th,.print-table tfoot td{font-weight:900}.charge-input{width:100%;min-width:48px;padding:3px 2px;border:1px solid #98a2b3;border-radius:3px;text-align:right;font:inherit;background:#fff}.row-total{font-weight:900}.screen-detail-link{white-space:nowrap;font-size:9px}.blank-row td{height:24px}.action-line{display:grid;grid-template-columns:145px 14px 1fr;margin-top:17px;font-size:12px;font-weight:800}.signature{width:250px;margin-top:42px;text-align:center}.signature-name{font-size:12px;font-weight:900;text-transform:uppercase}.signature-position{font-size:12px}.document-meta-note{margin-top:8px;font-size:9px;color:#333}.empty-preview{padding:30px;text-align:center;color:#667085;border:1px dashed #98a2b3;margin-top:18px}.muted{color:#667085}.badge{display:inline-block;padding:2px 6px;border-radius:999px;font-size:8px;font-weight:900}.badge.overdue{background:#fee4e2;color:#b42318}.badge.not-yet-due{background:#fef0c7;color:#b54708}.badge.unpaid{background:#eaecf0;color:#344054}
@media(max-width:1000px){.page-shell{padding:20px 12px 50px}.filter-grid,.stats{grid-template-columns:1fr 1fr}.bill-code-box{grid-template-columns:1fr 1fr}.field.span-2{grid-column:span 2}}@media(max-width:620px){.filter-grid,.stats,.bill-code-box{grid-template-columns:1fr}.field.span-2{grid-column:auto}.btn{width:100%}.actions,.form-actions{flex-direction:column;width:100%}.tabs{flex-direction:column}}
@page{size:A4 portrait;margin:10mm 9mm 12mm}
@media print{html,body{background:#fff}.navbar,.sidebar,.screen-only,.screen-header,.tabs,.card,.stats,.notice-message{display:none!important}.main-content{margin:0!important}.page-shell{max-width:none;padding:0}.paper-wrap{overflow:visible;padding:0;border:0;background:#fff}.print-sheet{width:auto;min-height:0;margin:0;padding:0;box-shadow:none}.document-header .line-1{font-size:12px}.document-header .line-2{font-size:15px}.document-header .line-3{font-size:12px}.memo-zone{font-size:14px}.memo-block{font-size:10px;grid-template-columns:110px 10px 1fr}.customer-detail-grid{font-size:9px;grid-template-columns:105px 1fr 105px 1fr}.print-table{font-size:7.2px;margin-top:10px}.print-table thead{display:table-header-group}.print-table tfoot{display:table-footer-group}.print-table tr{page-break-inside:avoid}.print-table th,.print-table td{padding:4px 3px}.charge-input{border:0;padding:0;background:transparent}.screen-only-cell{display:none!important}.action-line{grid-template-columns:110px 10px 1fr;font-size:10px}.signature{margin-top:35px}.document-meta-note{display:none}}
</style>
</head>
<body>
<?php require_once '../common/navbar.php'; ?>
<div class="main-content">
<main class="page-shell">
    <div class="screen-header screen-only">
        <div>
            <h1>Generate Disconnection Notice</h1>
            <div class="screen-sub">Generate either a detailed notice for one customer or the batch memorandum for one billing period.</div>
        </div>
        <div class="actions">
            <a class="btn light" href="reports_management.php">Back to Reports</a>
            <button class="btn primary" type="button" onclick="printNotice()">Print <?= $mode === 'single' ? 'Single Notice' : 'Batch Notice' ?></button>
        </div>
    </div>

    <div class="tabs screen-only">
        <a class="tab <?= $mode === 'batch' ? 'active' : '' ?>" href="<?= e($batchTabUrl) ?>">Batch Notice</a>
        <a class="tab <?= $mode === 'single' ? 'active' : '' ?>" href="<?= e($singleTabUrl) ?>">Single Customer Notice</a>
    </div>

    <?php if ($dataError): ?>
        <div class="notice-message error screen-only"><?= e($dataError) ?></div>
    <?php endif; ?>
    <div class="notice-message info screen-only">
        Penalty and Compromise remain optional manual values. This page does not calculate or save them. In Batch Notice, checked bill codes receive their own columns; every unchecked bill code with an unpaid amount is automatically totaled under <strong>Other Income</strong>, as required by the supplied format.
    </div>

    <form class="card screen-only" method="get">
        <div class="card-body">
            <input type="hidden" name="mode" value="<?= e($mode) ?>">
            <?php if ($mode === 'batch'): ?><input type="hidden" name="codes_submitted" value="1"><?php endif; ?>
            <div class="filter-grid">
                <div class="field">
                    <label>Month / Period</label>
                    <select name="month" required>
                        <?php if (!$availableMonths): ?><option value="">No unpaid period available</option><?php endif; ?>
                        <?php foreach ($availableMonths as $periodMonth): ?>
                            <option value="<?= e($periodMonth) ?>" <?= $month === $periodMonth ? 'selected' : '' ?>><?= e(date('F Y', strtotime($periodMonth . '-01'))) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Zone</label>
                    <select name="zone">
                        <option value="0">All Zones</option>
                        <?php foreach ($zones as $zoneOption): ?>
                            <option value="<?= e($zoneOption) ?>" <?= $zone === (int)$zoneOption ? 'selected' : '' ?>>Zone <?= e($zoneOption) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($mode === 'batch'): ?>
                    <div class="field span-2">
                        <label>Customer / Account Search</label>
                        <input name="q" value="<?= e($search) ?>" placeholder="Optional account number or customer name">
                    </div>
                <?php else: ?>
                    <div class="field span-2">
                        <label>Customer</label>
                        <select name="member_id" required>
                            <?php if (!$memberOptions): ?><option value="">No unpaid customer in this period</option><?php endif; ?>
                            <?php foreach ($memberOptions as $option): ?>
                                <option value="<?= e($option['member_id']) ?>" <?= $memberId === (int)$option['member_id'] ? 'selected' : '' ?>>
                                    <?= e(($option['account_number'] !== '' ? $option['account_number'] . ' - ' : '') . $option['member_name'] . ' (Zone ' . $option['zone'] . ')') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <div class="field">
                    <label>Memorandum No.</label>
                    <input name="memo_no" value="<?= e($memoNumber) ?>" placeholder="Optional">
                </div>
                <div class="field">
                    <label>Series Year</label>
                    <input name="series_year" value="<?= e($seriesYear) ?>">
                </div>
                <div class="field">
                    <label>Notice Date</label>
                    <input type="date" name="notice_date" value="<?= e($noticeDate) ?>" required>
                </div>
                <div class="field">
                    <label>From</label>
                    <input name="notice_from" value="<?= e($noticeFrom) ?>" required>
                </div>
                <div class="field full">
                    <label>To <?= $mode === 'single' ? '(leave blank to use the selected customer)' : '' ?></label>
                    <input name="notice_to" value="<?= e($noticeToInput) ?>" placeholder="<?= $mode === 'single' ? 'Selected customer' : 'Person assigned to notify the listed members' ?>">
                </div>
                <div class="field full">
                    <label>Remarks</label>
                    <textarea name="remarks"><?= e($remarks) ?></textarea>
                </div>

                <?php if ($mode === 'batch'): ?>
                    <div class="field full">
                        <label>Bill Code Columns</label>
                        <div class="bill-code-box">
                            <?php foreach ($billCodes as $code): $codeId = (int)($code['code_id'] ?? 0); ?>
                                <label class="bill-code-option">
                                    <input type="checkbox" name="bill_codes[]" value="<?= e($codeId) ?>" <?= in_array($codeId, $selectedBillCodeIds, true) ? 'checked' : '' ?>>
                                    <span>
                                        <strong><?= e($code['code'] ?? '') ?></strong>
                                        <small><?= e($code['description'] ?? '') ?></small>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            <div class="form-actions">
                <a class="btn light" href="<?= e($resetUrl) ?>">Reset</a>
                <button class="btn" type="submit">Apply and Preview</button>
            </div>
        </div>
    </form>

    <div class="stats screen-only">
        <?php if ($mode === 'batch'): ?>
            <div class="stat"><div class="stat-label">Period</div><div class="stat-value" style="font-size:17px"><?= e($periodCaption) ?></div></div>
            <div class="stat"><div class="stat-label">Zone</div><div class="stat-value" style="font-size:17px"><?= e($zoneCaption) ?></div></div>
            <div class="stat"><div class="stat-label">Customers Listed</div><div class="stat-value"><?= count($batchRows) ?></div></div>
            <div class="stat"><div class="stat-label">Principal Outstanding</div><div class="stat-value">₱ <?= number_format($batchPrincipalTotal, 2) ?></div></div>
        <?php else: ?>
            <div class="stat"><div class="stat-label">Period</div><div class="stat-value" style="font-size:17px"><?= e($periodCaption) ?></div></div>
            <div class="stat"><div class="stat-label">Account</div><div class="stat-value" style="font-size:17px"><?= e($memberDetails['account_number'] ?? '—') ?></div></div>
            <div class="stat"><div class="stat-label">Unpaid Items</div><div class="stat-value"><?= count($singleRows) ?></div></div>
            <div class="stat"><div class="stat-label">Principal Outstanding</div><div class="stat-value">₱ <?= number_format($singlePrincipalTotal, 2) ?></div></div>
        <?php endif; ?>
    </div>

    <div class="paper-wrap">
        <section class="print-sheet" id="printSheet" data-mode="<?= e($mode) ?>" data-period="<?= e($month) ?>">
            <header class="document-header">
                <div class="line-1"><?= e($noticeIssuer['name']) ?></div>
                <?php if ($noticeIssuer['phone'] !== ''): ?><div class="line-2">Tel. No. <?= e($noticeIssuer['phone']) ?></div><?php endif; ?>
                <div class="line-3"><?= e($noticeIssuer['address']) ?></div>
            </header>

            <div class="memo-zone"><?= e($zoneCaption) ?></div>
            <div class="memo-block">
                <div class="label">MEMORANDOM</div><div></div><div></div>
                <div class="label">No.</div><div>:</div><div class="value"><?= e($memoNumber) ?></div>
                <div class="label">Series of <?= e($seriesYear) ?></div><div></div><div></div>
                <div class="label">TO</div><div>:</div><div class="value"><?= e($noticeTo !== '' ? $noticeTo : '____________________________') ?></div>
                <div class="label">FROM</div><div>:</div><div class="value"><?= e($noticeFrom) ?></div>
                <div class="label">DATE</div><div>:</div><div class="value"><?= e(dnh_date_display($noticeDate)) ?></div>
                <div class="label">SUBJECT</div><div>:</div><div class="value">NOTICE OF DISCONNECTION</div>
                <div class="label">REMARKS</div><div>:</div><div class="remarks-value"><?= e($remarks) ?></div>
            </div>

            <?php if ($mode === 'single'): ?>
                <?php if (!$memberDetails || !$singleRows): ?>
                    <div class="empty-preview">No unpaid bill details are available for the selected customer and period.</div>
                <?php else: ?>
                    <div class="customer-detail-grid">
                        <div class="label">ACCOUNT NUMBER</div><div><?= e($memberDetails['account_number'] ?: $memberDetails['member_id']) ?></div>
                        <div class="label">CONCESSIONAIRE</div><div><?= e(strtoupper((string)$memberDetails['member_name'])) ?></div>
                        <div class="label">ADDRESS</div><div><?= e($memberDetails['address'] !== '' ? $memberDetails['address'] : 'Zone ' . $memberDetails['zone']) ?></div>
                        <div class="label">ZONE</div><div><?= e($memberDetails['zone'] ?: '—') ?></div>
                        <div class="label">METER SEQUENCE</div><div><?= e($memberDetails['meter_sequence'] !== '' ? $memberDetails['meter_sequence'] : '—') ?></div>
                        <div class="label">RATE CODE</div><div><?= e(trim((string)($memberDetails['rc_code'] ?? '') . ' ' . (string)($memberDetails['rc_name'] ?? '')) ?: '—') ?></div>
                        <div class="label">PIPE SIZE</div><div><?= e(trim((string)($memberDetails['pipe_size_label'] ?? $memberDetails['pipe_size_code'] ?? '')) ?: '—') ?></div>
                        <div class="label">CONNECTION STATUS</div><div><?= e(strtoupper((string)$memberDetails['connection_status'])) ?></div>
                        <div class="label">NOTICE PERIOD</div><div><?= e($periodCaption) ?></div>
                        <div class="label">EARLIEST DUE DATE</div><div><?= e(dnh_date_display($earliestDueDate)) ?></div>
                        <div class="label">DISCONNECTION DATE</div><div><?= e(dnh_date_display($earliestDisconnectionDate)) ?></div>
                    </div>

                    <table class="print-table" id="singleNoticeTable">
                        <thead>
                            <tr>
                                <th style="width:14%">MONTH / PERIOD</th>
                                <th style="width:15%">BILL CODE</th>
                                <th style="width:12%">BILL TYPE</th>
                                <th style="width:9%">BILL DATE</th>
                                <th style="width:9%">DUE DATE</th>
                                <th style="width:10%">DISCONNECTION DATE</th>
                                <th style="width:9%">PRINCIPAL</th>
                                <th style="width:7%">PENALTY</th>
                                <th style="width:7%">COMPROMISE</th>
                                <th style="width:8%">TOTAL</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($singleRows as $row): $statusClass = strtolower(str_replace(' ', '-', (string)$row['due_status'])); ?>
                                <tr class="single-charge-row" data-principal="<?= e(number_format((float)$row['principal_balance'], 2, '.', '')) ?>">
                                    <td><?= e($row['period_label']) ?></td>
                                    <td><strong><?= e($row['bill_code']) ?></strong><br><span class="muted"><?= e($row['bill_code_description']) ?></span></td>
                                    <td><?= e($row['bill_type']) ?> #<?= e($row['source_id']) ?><br><span class="badge <?= e($statusClass) ?>"><?= e($row['due_status']) ?></span></td>
                                    <td class="center"><?= e(dnh_date_display($row['bill_date'])) ?></td>
                                    <td class="center"><?= e(dnh_date_display($row['due_date'])) ?></td>
                                    <td class="center"><?= e(dnh_date_display($row['disconnection_date'])) ?></td>
                                    <td class="num">₱ <?= number_format((float)$row['principal_balance'], 2) ?></td>
                                    <td class="num"><input class="charge-input penalty" type="number" min="0" step="0.01" value="0.00" oninput="recalculateSingle()"></td>
                                    <td class="num"><input class="charge-input compromise" type="number" min="0" step="0.01" value="0.00" oninput="recalculateSingle()"></td>
                                    <td class="num row-total">₱ <?= number_format((float)$row['principal_balance'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <th colspan="6" style="text-align:right">TOTAL OUTSTANDING</th>
                                <th class="num" id="singlePrincipalFooter">₱ <?= number_format($singlePrincipalTotal, 2) ?></th>
                                <th class="num" id="singlePenaltyFooter">₱ 0.00</th>
                                <th class="num" id="singleCompromiseFooter">₱ 0.00</th>
                                <th class="num" id="singleGrandFooter">₱ <?= number_format($singlePrincipalTotal, 2) ?></th>
                            </tr>
                        </tfoot>
                    </table>
                <?php endif; ?>
            <?php else: ?>
                <?php if (!$batchRows): ?>
                    <div class="empty-preview">No unpaid customers are available for the selected period, zone, and search.</div>
                <?php else: ?>
                    <table class="print-table" id="batchNoticeTable">
                        <thead>
                            <tr>
                                <th style="width:5%">SEQ.<br>NUM</th>
                                <th style="width:8%">ACCT.<br>NUM</th>
                                <th style="width:19%">CONCESSIONAIRE</th>
                                <?php foreach ($selectedBillCodes as $code): ?>
                                    <th><?= e(strtoupper((string)($code['code'] ?? 'BILL CODE'))) ?></th>
                                <?php endforeach; ?>
                                <th>OTHER<br>INCOME</th>
                                <th>PENALTY</th>
                                <th>COMPROMISE</th>
                                <th>TOTAL</th>
                                <th class="screen-only-cell screen-only">DETAIL</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($batchRows as $row): ?>
                                <tr class="batch-charge-row" data-principal="<?= e(number_format((float)$row['principal_total'], 2, '.', '')) ?>">
                                    <td class="center"><?= e($row['meter_sequence'] !== '' ? $row['meter_sequence'] : '0') ?></td>
                                    <td class="center"><?= e($row['account_number'] !== '' ? $row['account_number'] : $row['member_id']) ?></td>
                                    <td class="concessionaire"><?= e($row['member_name']) ?></td>
                                    <?php foreach ($selectedBillCodes as $code): $codeId = (int)($code['code_id'] ?? 0); ?>
                                        <td class="num"><?= number_format((float)($row['selected_amounts'][$codeId] ?? 0), 2) ?></td>
                                    <?php endforeach; ?>
                                    <td class="num"><?= number_format((float)$row['other_income'], 2) ?></td>
                                    <td class="num"><input class="charge-input penalty" type="number" min="0" step="0.01" value="0.00" oninput="recalculateBatch()"></td>
                                    <td class="num"><input class="charge-input compromise" type="number" min="0" step="0.01" value="0.00" oninput="recalculateBatch()"></td>
                                    <td class="num row-total">₱ <?= number_format((float)$row['principal_total'], 2) ?></td>
                                    <td class="screen-only-cell screen-only center">
                                        <a class="screen-detail-link" href="<?= e(dnh_query_url([
                                            'mode' => 'single',
                                            'month' => $month,
                                            'zone' => $zone ?: null,
                                            'member_id' => $row['member_id'],
                                            'notice_from' => $noticeFrom,
                                            'notice_date' => $noticeDate,
                                            'memo_no' => $memoNumber,
                                            'series_year' => $seriesYear,
                                        ])) ?>">Single Notice</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php for ($blank = count($batchRows); $blank < 18; $blank++): ?>
                                <tr class="blank-row">
                                    <?php for ($column = 0; $column < 7 + count($selectedBillCodes); $column++): ?><td>&nbsp;</td><?php endfor; ?>
                                    <td class="screen-only-cell screen-only"></td>
                                </tr>
                            <?php endfor; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <th colspan="<?= 3 + count($selectedBillCodes) ?>" style="text-align:right">TOTAL</th>
                                <th class="num" id="batchOtherFooter">₱ <?= number_format(array_sum(array_map(static fn(array $row): float => (float)$row['other_income'], $batchRows)), 2) ?></th>
                                <th class="num" id="batchPenaltyFooter">₱ 0.00</th>
                                <th class="num" id="batchCompromiseFooter">₱ 0.00</th>
                                <th class="num" id="batchGrandFooter">₱ <?= number_format($batchPrincipalTotal, 2) ?></th>
                                <th class="screen-only-cell screen-only"></th>
                            </tr>
                        </tfoot>
                    </table>
                <?php endif; ?>
            <?php endif; ?>

            <div class="action-line"><div>ACTION TAKEN</div><div>:</div><div>For immediate compliance</div></div>
            <div class="signature">
                <div class="signature-name"><?= e($managerName !== '' ? $managerName : '____________________________') ?></div>
                <div class="signature-position">Manager</div>
            </div>
            <div class="document-meta-note">Period: <?= e($periodCaption) ?> | <?= e($zoneCaption) ?></div>
        </section>
    </div>
</main>
</div>
<script>
const phpMoney = value => '₱ ' + Number(value || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
const inputAmount = (row, selector) => Math.max(0, Number(row.querySelector(selector)?.value || 0));

function recalculateSingle() {
    let principal = 0;
    let penalty = 0;
    let compromise = 0;
    document.querySelectorAll('.single-charge-row').forEach(row => {
        const rowPrincipal = Number(row.dataset.principal || 0);
        const rowPenalty = inputAmount(row, '.penalty');
        const rowCompromise = inputAmount(row, '.compromise');
        principal += rowPrincipal;
        penalty += rowPenalty;
        compromise += rowCompromise;
        row.querySelector('.row-total').textContent = phpMoney(rowPrincipal + rowPenalty + rowCompromise);
    });
    const principalFooter = document.getElementById('singlePrincipalFooter');
    if (principalFooter) principalFooter.textContent = phpMoney(principal);
    const penaltyFooter = document.getElementById('singlePenaltyFooter');
    if (penaltyFooter) penaltyFooter.textContent = phpMoney(penalty);
    const compromiseFooter = document.getElementById('singleCompromiseFooter');
    if (compromiseFooter) compromiseFooter.textContent = phpMoney(compromise);
    const grandFooter = document.getElementById('singleGrandFooter');
    if (grandFooter) grandFooter.textContent = phpMoney(principal + penalty + compromise);
}

function recalculateBatch() {
    let principal = 0;
    let penalty = 0;
    let compromise = 0;
    document.querySelectorAll('.batch-charge-row').forEach(row => {
        const rowPrincipal = Number(row.dataset.principal || 0);
        const rowPenalty = inputAmount(row, '.penalty');
        const rowCompromise = inputAmount(row, '.compromise');
        principal += rowPrincipal;
        penalty += rowPenalty;
        compromise += rowCompromise;
        row.querySelector('.row-total').textContent = phpMoney(rowPrincipal + rowPenalty + rowCompromise);
    });
    const penaltyFooter = document.getElementById('batchPenaltyFooter');
    if (penaltyFooter) penaltyFooter.textContent = phpMoney(penalty);
    const compromiseFooter = document.getElementById('batchCompromiseFooter');
    if (compromiseFooter) compromiseFooter.textContent = phpMoney(compromise);
    const grandFooter = document.getElementById('batchGrandFooter');
    if (grandFooter) grandFooter.textContent = phpMoney(principal + penalty + compromise);
}

function printNotice() {
    const sheet = document.getElementById('printSheet');
    if (!sheet?.dataset.period) {
        alert('Select one month or billing period before printing the disconnection notice.');
        return;
    }
    const mode = sheet.dataset.mode;
    const rows = document.querySelectorAll(mode === 'single' ? '.single-charge-row' : '.batch-charge-row');
    if (!rows.length) {
        alert('There are no unpaid bill details to print for the selected notice.');
        return;
    }
    if (mode === 'single') recalculateSingle(); else recalculateBatch();
    window.print();
}

recalculateSingle();
recalculateBatch();
</script>
</body>
</html>
