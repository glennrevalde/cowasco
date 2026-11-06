<?php
/* AUTH_GUARD_START */
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../process/accessControlHelpers.php';
$__authGuardPdo = getDB();
requirePageAccess($__authGuardPdo, basename(__FILE__, '.php'));
unset($__authGuardPdo);
/* AUTH_GUARD_END */

// bill_period.php
require_once __DIR__ . '/../database/config.php';

require_once '../database/config.php';

$pdo = getDB();


if (!function_exists('e')) {
    function e($value) {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

function fmt_datetime_display($value) {
    if (empty($value)) return 'N/A';

    $time = strtotime((string)$value);
    return $time ? date('m/d/Y h:i A', $time) : 'N/A';
}

$periods = [];

try {
    $stmt = $pdo->query("
        SELECT 
            bp.*, 
            COALESCE(NULLIF(u_open.description, ''), u_open.name) AS opener_name, 
            COALESCE(NULLIF(u_close.description, ''), u_close.name) AS closer_name 
        FROM bill_periods bp
        LEFT JOIN operator u_open  ON bp.opened_by = u_open.pkey
        LEFT JOIN operator u_close ON bp.closed_by = u_close.pkey
        ORDER BY bp.start_date DESC, bp.period_id DESC
    ");
    $periods = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $ex) {
    $_SESSION['error_msg'] = "Database error: " . $ex->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../common/fonts.css">
<title>Bill Period Management - <?= htmlspecialchars(app_name(), ENT_QUOTES, 'UTF-8') ?></title>

<style>
    *, *::before, *::after {
        box-sizing: border-box;
        margin: 0;
        padding: 0;
    }

    :root {
        --white: #ffffff;
        --off-white: #f8f9fb;
        --border: #e8eaed;
        --border-focus: #1a1a2e;
        --text-primary: #0f0f1a;
        --text-secondary: #6b7280;
        --text-muted: #9ca3af;
        --accent: #1a1a2e;
        --success: #059669;
        --success-bg: #ecfdf5;
        --danger: #dc2626;
        --danger-bg: #fef2f2;
        --warning: #b45309;
        --warning-bg: #fffbeb;
        --info: #0369a1;
        --info-bg: #f0f9ff;
        --radius-sm: 8px;
        --radius: 14px;
        --shadow-sm: 0 1px 3px rgba(0,0,0,.06), 0 1px 2px rgba(0,0,0,.04);
    }

    body {
        font-family: 'Sora', sans-serif;
        background: var(--off-white);
        color: var(--text-primary);
        min-height: 100vh;
    }

    .main-content {
        min-height: 100vh;
    }

    .page {
        padding: 32px 32px 60px;
        animation: fadeIn .4s ease both;
    }

    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(12px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .header {
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        margin-bottom: 24px;
        gap: 12px;
    }

    .back-link {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        color: var(--text-secondary);
        text-decoration: none;
        font-size: .8rem;
        font-weight: 600;
        margin-bottom: 4px;
    }

    .back-link:hover {
        color: var(--accent);
    }

    .title {
        font-size: 1.5rem;
        font-weight: 700;
        letter-spacing: -.02em;
    }

    .subtitle {
        font-size: .875rem;
        color: var(--text-secondary);
        margin-top: 4px;
        line-height: 1.5;
    }

    .card {
        position: relative;
        overflow: visible;
        background: var(--white);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        padding: 24px;
        margin-bottom: 24px;
        box-shadow: var(--shadow-sm);
    }

    .card-title {
        font-size: 1.1rem;
        font-weight: 600;
        margin-bottom: 16px;
        padding-bottom: 12px;
        border-bottom: 1px solid var(--border);
    }

    .form-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(180px, 1fr));
        gap: 16px;
        margin-bottom: 18px;
        align-items: end;
    }

    .form-group {
        display: flex;
        flex-direction: column;
        gap: 6px;
        min-width: 0;
    }

    label {
        font-size: .78rem;
        font-weight: 700;
        color: var(--text-secondary);
        line-height: 1.3;
    }

    input, select {
        padding: 11px 12px;
        border: 1.5px solid var(--border);
        border-radius: var(--radius-sm);
        font-family: inherit;
        font-size: .875rem;
        outline: none;
        width: 100%;
        background: var(--white);
        transition: border-color .2s, box-shadow .2s, background .2s;
    }

    input:focus, select:focus {
        border-color: var(--border-focus);
        box-shadow: 0 0 0 3px rgba(26,26,46,.07);
    }

    input[readonly] {
        background: var(--off-white);
        color: var(--text-secondary);
        cursor: not-allowed;
    }

    .date-wrap {
        position: relative;
        z-index: 5;
    }

    .date-wrap input {
        padding-right: 38px;
        cursor: pointer;
        background: #fff;
    }

    .date-wrap .cal-icon {
        position: absolute;
        right: 11px;
        top: 50%;
        transform: translateY(-50%);
        pointer-events: none;
        color: var(--text-muted);
        display: flex;
        align-items: center;
    }


    .period-span {
        display: flex;
        flex-wrap: wrap;
        align-items: baseline;
        gap: 8px 12px;
        margin: -2px 0 16px;
        padding: 11px 13px;
        border: 1px solid var(--border);
        border-radius: var(--radius-sm);
        background: #f9fafb;
        color: var(--text-secondary);
        font-size: .8rem;
        line-height: 1.5;
    }

    .period-span strong {
        color: var(--text-primary);
        font-weight: 800;
    }

    .period-span.invalid {
        border-color: #fecaca;
        background: var(--danger-bg);
        color: var(--danger);
    }

    .field-note,
    .cutoff-time {
        display: block;
        margin-top: 3px;
        color: var(--text-muted);
        font-size: .7rem;
        font-weight: 500;
        line-height: 1.35;
    }

    .btn-group {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
        margin-top: 16px;
    }

    .btn {
        padding: 10px 20px;
        border: none;
        border-radius: var(--radius-sm);
        font-family: inherit;
        font-size: .875rem;
        font-weight: 700;
        cursor: pointer;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        transition: transform .2s, background .2s, border-color .2s;
    }

    .btn-primary {
        background: var(--accent);
        color: var(--white);
    }

    .btn-primary:hover {
        background: #0f0f1a;
        transform: translateY(-1px);
    }

    .btn-secondary {
        background: var(--white);
        color: var(--text-primary);
        border: 1.5px solid var(--border);
    }

    .btn-secondary:hover {
        border-color: var(--border-focus);
    }

    .alert {
        padding: 12px 16px;
        border-radius: var(--radius-sm);
        margin-bottom: 20px;
        font-weight: 700;
        font-size: .875rem;
        line-height: 1.5;
    }

    .alert.success {
        background: var(--success-bg);
        color: var(--success);
        border: 1px solid #a7f3d0;
    }

    .alert.danger {
        background: var(--danger-bg);
        color: var(--danger);
        border: 1px solid #fecaca;
    }

    .table-container {
        overflow-x: auto;
        border: 1px solid var(--border);
        border-radius: var(--radius);
    }

    table {
        width: 100%;
        border-collapse: collapse;
        font-size: .85rem;
        white-space: nowrap;
        background: #fff;
    }

    th {
        text-align: left;
        padding: 12px 16px;
        color: var(--text-secondary);
        border-bottom: 1.5px solid var(--border);
        font-weight: 800;
        background: #f9fafb;
    }

    td {
        padding: 14px 16px;
        border-bottom: 1px solid var(--border);
        vertical-align: middle;
    }

    tr:last-child td {
        border-bottom: none;
    }

    .mono {
        font-family: 'JetBrains Mono', monospace;
        font-size: .8rem;
    }

    .badge {
        padding: 4px 10px;
        border-radius: 20px;
        font-size: .7rem;
        font-weight: 800;
        display: inline-flex;
    }

    .badge-open {
        background: var(--success-bg);
        color: var(--success);
    }

    .badge-close {
        background: var(--danger-bg);
        color: var(--danger);
    }

    .action-links {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 12px;
    }

    .action-links a {
        color: var(--text-secondary);
        text-decoration: none;
        font-weight: 700;
        font-size: .8rem;
        transition: color .2s;
    }

    .action-links a:hover {
        color: var(--accent);
    }

    .action-links a.close-action:hover {
        color: #d97706;
    }

    .action-links a.delete-action:hover {
        color: var(--danger);
    }

    .inline-form {
        display: inline;
    }

    /* Flatpickr UI fixes */
    .flatpickr-calendar,
    .flatpickr-calendar * {
        box-sizing: border-box !important;
    }

    .flatpickr-calendar {
        font-family: 'Sora', sans-serif !important;
        border: 1.5px solid var(--border-focus) !important;
        border-radius: var(--radius) !important;
        box-shadow: 0 18px 45px rgba(15, 23, 42, .22) !important;
        overflow: hidden !important;
        z-index: 1000000 !important;
    }

    .flatpickr-calendar.open {
        z-index: 1000000 !important;
    }

    .flatpickr-months {
        height: 42px !important;
        background: var(--accent) !important;
        padding: 0 !important;
        display: flex !important;
        align-items: center !important;
    }

    .flatpickr-month {
        height: 42px !important;
        overflow: visible !important;
    }

    .flatpickr-prev-month,
    .flatpickr-next-month {
        height: 42px !important;
        padding: 12px 14px !important;
        color: #fff !important;
        fill: #fff !important;
    }

    .flatpickr-month,
    .flatpickr-prev-month,
    .flatpickr-next-month {
        color: #fff !important;
        fill: #fff !important;
    }

    .flatpickr-prev-month:hover svg,
    .flatpickr-next-month:hover svg {
        fill: #fff !important;
        opacity: .7;
    }

    .flatpickr-current-month {
        height: 42px !important;
        padding: 0 !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        gap: 6px !important;
        font-size: .86rem !important;
    }

    .flatpickr-current-month input.cur-year,
    .flatpickr-current-month .flatpickr-monthDropdown-months {
        height: 28px !important;
        line-height: 28px !important;
        padding: 0 4px !important;
        border: 0 !important;
        outline: 0 !important;
        box-shadow: none !important;
        color: #fff !important;
        font-family: 'Sora', sans-serif !important;
        font-weight: 800 !important;
        background: transparent !important;
    }

    .flatpickr-current-month .flatpickr-monthDropdown-months {
        width: auto !important;
        min-width: 72px !important;
        appearance: none !important;
        -webkit-appearance: none !important;
        -moz-appearance: none !important;
        cursor: pointer !important;
    }

    .flatpickr-current-month input.cur-year {
        width: 58px !important;
    }

    .flatpickr-current-month .flatpickr-monthDropdown-months option {
        color: var(--text-primary) !important;
        background: #fff !important;
    }

    .flatpickr-weekdays {
        height: 30px !important;
        background: var(--off-white) !important;
    }

    .flatpickr-weekday {
        font-family: 'Sora', sans-serif !important;
        font-weight: 800;
        font-size: .7rem;
        color: var(--text-muted) !important;
        background: var(--off-white) !important;
    }

    .flatpickr-day {
        font-family: 'Sora', sans-serif !important;
        font-size: .8rem;
        border-radius: 8px !important;
    }

    .flatpickr-day.selected,
    .flatpickr-day.selected:hover {
        background: var(--accent) !important;
        border-color: var(--accent) !important;
        color: #fff !important;
    }

    .flatpickr-day:hover {
        background: var(--off-white) !important;
    }

    .flatpickr-day.today {
        border-color: var(--accent) !important;
        color: var(--accent) !important;
        font-weight: 800;
    }

    .flatpickr-day.today.selected {
        color: #fff !important;
    }

    .numInputWrapper span {
        border-color: var(--border) !important;
    }

    .numInputWrapper span:hover {
        background: var(--off-white) !important;
    }

    @media (max-width: 1100px) {
        .form-grid {
            grid-template-columns: repeat(2, minmax(180px, 1fr));
        }
    }

    @media (max-width: 768px) {
        .page {
            padding: 20px 16px;
        }

        .form-grid {
            grid-template-columns: 1fr;
        }

        .btn {
            width: 100%;
        }
    }
</style>
</head>
<body>

<?php require_once '../common/navbar.php'; ?>

<div class="main-content">
<main class="page">

    <div class="header">
        <a href="billing_management.php" class="back-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="14" height="14">
                <line x1="19" y1="12" x2="5" y2="12"></line>
                <polyline points="12 19 5 12 12 5"></polyline>
            </svg>
            Back to Billing Management
        </a>

        <div style="width: 100%;">
            <h1 class="title">Bill Period Management</h1>
            <p class="subtitle">
                Create and manage reading and billing cycles. The end date is editable when the actual cutoff differs from the default schedule.
            </p>
        </div>
    </div>

    <?php if (isset($_SESSION['success_msg'])): ?>
        <div class="alert success">
            <?= e($_SESSION['success_msg']); unset($_SESSION['success_msg']); ?>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error_msg'])): ?>
        <div class="alert danger">
            <?= e($_SESSION['error_msg']); unset($_SESSION['error_msg']); ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <h2 class="card-title">Create New Period</h2>

        <form action="../process/addBillPeriod.php" method="POST"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <div class="form-grid">
                <div class="form-group">
                    <label for="duration">Duration</label>
                    <select name="duration" id="duration" required>
                        <option value="1">Monthly (1 month)</option>
                        <option value="3">Quarterly (3 months)</option>
                        <option value="6">Semi-Annual (6 months)</option>
                        <option value="12">Annual (12 months)</option>
                    </select>
                    <span class="field-note">Sets the default cycle length. A manually entered To Date overrides the default.</span>
                </div>

                <div class="form-group">
                    <label for="date_from">From Date &amp; Time</label>
                    <div class="date-wrap">
                        <input type="datetime-local" name="date_from" id="date_from" step="60" required>
                        <span class="cal-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                                 fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                                <rect x="3" y="4" width="18" height="18" rx="2"/>
                                <line x1="16" y1="2" x2="16" y2="6"/>
                                <line x1="8" y1="2" x2="8" y2="6"/>
                                <line x1="3" y1="10" x2="21" y2="10"/>
                            </svg>
                        </span>
                    </div>
                    <span class="field-note">Default: previous month’s last day, cutoff at 12:00 PM.</span>
                </div>

                <div class="form-group">
                    <label for="date_to">To Date &amp; Time</label>
                    <div class="date-wrap">
                        <input type="datetime-local" name="date_to" id="date_to" step="60" required>
                        <span class="cal-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                                 fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                                <rect x="3" y="4" width="18" height="18" rx="2"/>
                                <line x1="16" y1="2" x2="16" y2="6"/>
                                <line x1="8" y1="2" x2="8" y2="6"/>
                                <line x1="3" y1="10" x2="21" y2="10"/>
                            </svg>
                        </span>
                    </div>
                    <span class="field-note">Editable. Default: current cycle’s month-end, cutoff at 11:59 AM.</span>
                </div>

                <div class="form-group">
                    <label for="period_code">Period Code</label>
                    <input type="text" name="period_code" id="period_code" placeholder="e.g. 052626_062626" readonly required>
                </div>
            </div>

            <div class="period-span" id="period_span" aria-live="polite">
                <span>Measured cutoff span:</span>
                <strong id="period_span_value">Select a valid start and end date.</strong>
            </div>

            <div class="btn-group">
                <button type="submit" class="btn btn-primary">Save Period</button>
                <button type="button" class="btn btn-secondary" id="clear_btn">Clear Form</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h2 class="card-title">Billing Periods Ledger</h2>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>From</th>
                        <th>To</th>
                        <th>Status</th>
                        <th>Opened By</th>
                        <th>Closed By</th>
                        <th>Closed On</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (empty($periods)): ?>
                        <tr>
                            <td colspan="8" style="text-align:center;padding:30px;color:var(--text-muted);">
                                No billing periods have been created yet.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($periods as $p): ?>
                            <tr>
                                <td class="mono"><?= e($p['bp_code']) ?></td>
                                <td>
                                    <?= e(fmt_datetime_display($p['start_date'])) ?>
                                </td>
                                <td>
                                    <?= e(fmt_datetime_display($p['end_date'])) ?>
                                </td>
                                <td>
                                    <?php if (($p['status'] ?? '') === 'open'): ?>
                                        <span class="badge badge-open">OPEN</span>
                                    <?php else: ?>
                                        <span class="badge badge-close">CLOSED</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= e($p['opener_name'] ?? 'System') ?></td>
                                <td <?= empty($p['closer_name']) ? 'style="color:var(--text-muted)"' : '' ?>>
                                    <?= e($p['closer_name'] ?? 'N/A') ?>
                                </td>
                                <td <?= empty($p['closed_at']) ? 'style="color:var(--text-muted)"' : '' ?>>
                                    <?= e(!empty($p['closed_at']) ? fmt_datetime_display($p['closed_at']) : 'N/A') ?>
                                </td>
                                <td>
                                    <div class="action-links">
                                        <a href="meter_reading_list_print.php?period_id=<?= e($p['period_id']) ?>" target="_blank" class="print-action">
                                            Print Meter List
                                        </a>

                                        <?php if (($p['status'] ?? '') === 'open'): ?>
                                            <form action="../process/editBillPeriod.php" method="POST" class="inline-form" data-confirm="Close this period? New readings and posting will be blocked."><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="period_id" value="<?= e($p['period_id']) ?>">
                                                <input type="hidden" name="action" value="close">
                                                <a href="#" class="close-action"
                                                   onclick="this.closest('form').requestSubmit(); return false;">
                                                    Close Period
                                                </a>
                                            </form>
                                        <?php else: ?>
                                            <form action="../process/editBillPeriod.php" method="POST" class="inline-form" data-confirm="Reopen this period? Reopening is blocked when billed or paid readings exist."><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="period_id" value="<?= e($p['period_id']) ?>">
                                                <input type="hidden" name="action" value="reopen">
                                                <a href="#" class="close-action"
                                                   onclick="this.closest('form').requestSubmit(); return false;">
                                                    Reopen
                                                </a>
                                            </form>
                                        <?php endif; ?>

                                        <form action="../process/deleteBillPeriod.php" method="POST" class="inline-form" data-confirm="Delete this period? Only completely unused periods can be deleted."><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="period_id" value="<?= e($p['period_id']) ?>">
                                            <a href="#" class="delete-action"
                                               onclick="this.closest('form').requestSubmit(); return false;">
                                                Delete
                                            </a>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</main>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const durationSelect = document.getElementById('duration');
    const dateFromInput = document.getElementById('date_from');
    const dateToInput = document.getElementById('date_to');
    const periodCodeInput = document.getElementById('period_code');
    const clearBtn = document.getElementById('clear_btn');
    const periodForm = dateFromInput.closest('form');
    const periodSpan = document.getElementById('period_span');
    const periodSpanValue = document.getElementById('period_span_value');

    function parseLocalDateTime(value) {
        const match = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/);
        if (!match) return null;
        const date = new Date(
            Number(match[1]),
            Number(match[2]) - 1,
            Number(match[3]),
            Number(match[4]),
            Number(match[5]),
            0,
            0
        );
        if (
            date.getFullYear() !== Number(match[1]) ||
            date.getMonth() !== Number(match[2]) - 1 ||
            date.getDate() !== Number(match[3]) ||
            date.getHours() !== Number(match[4]) ||
            date.getMinutes() !== Number(match[5])
        ) return null;
        return date;
    }

    function toLocalInput(date) {
        const pad = value => String(value).padStart(2, '0');
        return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
    }

    function addMonthsClamped(date, months) {
        const originalDay = date.getDate();
        const targetFirst = new Date(date.getFullYear(), date.getMonth() + months, 1, 0, 0, 0, 0);
        const targetLastDay = new Date(targetFirst.getFullYear(), targetFirst.getMonth() + 1, 0).getDate();
        return new Date(targetFirst.getFullYear(), targetFirst.getMonth(), Math.min(originalDay, targetLastDay), 0, 0, 0, 0);
    }

    function isLastDayOfMonth(date) {
        return date.getDate() === new Date(date.getFullYear(), date.getMonth() + 1, 0).getDate();
    }

    function defaultEndFromStart(start, months) {
        let end;
        if (isLastDayOfMonth(start)) {
            end = new Date(start.getFullYear(), start.getMonth() + months + 1, 0, 11, 59, 0, 0);
        } else {
            end = addMonthsClamped(start, months);
            end.setDate(end.getDate() - 1);
            end.setHours(11, 59, 0, 0);
        }
        return end;
    }

    function buildCode(start, end) {
        const pad = value => String(value).padStart(2, '0');
        const segment = date => `${pad(date.getMonth() + 1)}${pad(date.getDate())}${String(date.getFullYear()).slice(-2)}`;
        return `${segment(start)}_${segment(end)}`;
    }

    function updateMeasuredSpan(start, end) {
        const milliseconds = end.getTime() - start.getTime();
        if (!Number.isFinite(milliseconds) || milliseconds <= 0) {
            periodSpan.classList.add('invalid');
            periodSpanValue.textContent = 'End date and time must be later than the start cutoff.';
            periodCodeInput.value = '';
            return false;
        }
        const totalMinutes = Math.floor(milliseconds / 60000);
        const days = Math.floor(totalMinutes / 1440);
        const hours = Math.floor((totalMinutes % 1440) / 60);
        const minutes = totalMinutes % 60;
        periodSpan.classList.remove('invalid');
        periodSpanValue.textContent = `${days} day${days === 1 ? '' : 's'}, ${hours} hour${hours === 1 ? '' : 's'}, and ${minutes} minute${minutes === 1 ? '' : 's'}.`;
        periodCodeInput.value = buildCode(start, end);
        return true;
    }

    function recalculateDefaultEnd() {
        const start = parseLocalDateTime(dateFromInput.value);
        if (!start) {
            dateToInput.value = '';
            periodCodeInput.value = '';
            periodSpanValue.textContent = 'Select a valid start and end date/time.';
            return;
        }
        const months = Number.parseInt(durationSelect.value, 10) || 1;
        const end = defaultEndFromStart(start, months);
        dateToInput.value = toLocalInput(end);
        updateMeasuredSpan(start, end);
    }

    function refreshSubmittedRange() {
        const start = parseLocalDateTime(dateFromInput.value);
        const end = parseLocalDateTime(dateToInput.value);
        if (!start || !end) {
            periodCodeInput.value = '';
            periodSpanValue.textContent = 'Select a valid start and end date/time.';
            return false;
        }
        return updateMeasuredSpan(start, end);
    }

    function applyDefaultMonthlyCutoff() {
        const today = new Date();
        const start = new Date(today.getFullYear(), today.getMonth(), 0, 12, 0, 0, 0);
        dateFromInput.value = toLocalInput(start);
        recalculateDefaultEnd();
    }

    durationSelect.addEventListener('change', recalculateDefaultEnd);
    dateFromInput.addEventListener('change', recalculateDefaultEnd);
    dateToInput.addEventListener('change', refreshSubmittedRange);

    periodForm.addEventListener('submit', function (event) {
        if (!refreshSubmittedRange()) {
            event.preventDefault();
            alert('Please select a valid billing-period date/time range.');
        }
    });

    clearBtn.addEventListener('click', function () {
        dateFromInput.value = '';
        dateToInput.value = '';
        periodCodeInput.value = '';
        periodSpan.classList.remove('invalid');
        periodSpanValue.textContent = 'Select a valid start and end date/time.';
    });

    applyDefaultMonthlyCutoff();
});
</script>

</body>
</html>
