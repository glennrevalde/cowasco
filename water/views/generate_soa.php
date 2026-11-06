<?php
/* AUTH_GUARD_START */
require_once __DIR__ . '/../database/config.php';
$soaMode = strtolower(trim((string)($_GET['view'] ?? $_GET['tab'] ?? ''))) === 'soa'
    || strtolower(trim((string)($_GET['tab'] ?? ''))) === 'print';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../process/accessControlHelpers.php';
$__authGuardPdo = getDB();
requirePageAccess($__authGuardPdo, $soaMode ? 'reports_management' : basename(__FILE__, '.php'));
unset($__authGuardPdo);
/* AUTH_GUARD_END */

require_once __DIR__ . '/../database/config.php';

require_once '../database/config.php';
require_once '../process/organizationUnitHelpers.php';
require_once __DIR__ . '/../common/institutionConfigurationHelpers.php';
$pdo = getDB();
// the cooperative issues the statement (company settings, then the institution profile)
$soaIssuer = institution_issuer($pdo);

if (!function_exists('e')) {
    function e($value): string { return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

// The same secured page serves two separate workspaces:
// - normal route: Post Billing only
// - Reports Management route (?view=soa): SOA generation only

$loadErrors = [];
try {
    $stmt = $pdo->query("
        SELECT bp.*,
               COALESCE(SUM(CASE
                   WHEN br.reading_id IS NOT NULL
                    AND br.is_billed = 0
                    AND br.is_paid = 0
                    AND COALESCE(br.bill_lifecycle_status, 'active') = 'active'
                   THEN 1 ELSE 0 END), 0) AS pending_count,
               COALESCE(SUM(CASE
                   WHEN br.reading_id IS NOT NULL
                    AND br.is_billed = 1
                    AND COALESCE(br.bill_lifecycle_status, 'active') = 'active'
                   THEN 1 ELSE 0 END), 0) AS posted_count,
               COUNT(br.reading_id) AS reading_count
        FROM bill_periods bp
        LEFT JOIN bill_readings br ON br.period_id = bp.period_id
        WHERE bp.status = 'open'
        GROUP BY bp.period_id, bp.bp_code, bp.start_date, bp.end_date, bp.status,
                 bp.opened_by, bp.closed_by, bp.created_at, bp.updated_at, bp.closed_at
        ORDER BY bp.start_date DESC, bp.period_id DESC
    ");
    $openPeriods = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $pdo->query("SELECT * FROM bill_periods ORDER BY start_date DESC, period_id DESC");
    $allPeriods = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $ex) {
    $openPeriods = [];
    $allPeriods = [];
    $loadErrors[] = 'Billing periods could not be loaded.';
    error_log('SOA billing-period load error: ' . $ex->getMessage());
}

// the posting asks for the class (Chart of Accounts > Class List); branch, segment and division are no longer asked
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../common/fonts.css">
    <link rel="stylesheet" href="../../assets/vendor/flatpickr/flatpickr.min.css">
    <title><?= $soaMode ? 'Generate Statement of Account' : 'Post Billing' ?> - <?= htmlspecialchars(app_name(), ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --white: #ffffff; --off-white: #f8f9fb; --border: #e8eaed; --border-focus: #1a1a2e;
            --text-primary: #0f0f1a; --text-secondary: #6b7280; --text-muted: #9ca3af;
            --accent: #1a1a2e; --success: #059669; --success-bg: #ecfdf5; 
            --error: #dc2626; --error-bg: #fef2f2; --info: #2563eb; --info-bg: #eff6ff; 
            --warning: #d97706; --warning-bg: #fffbeb;
            --radius-sm: 8px; --radius: 14px; --shadow-sm: 0 1px 3px rgba(0,0,0,.06); --shadow-lg: 0 10px 40px rgba(0,0,0,.12);
        }
        html { height: 100%; font-size: 16px; }
        body { font-family: 'Sora', sans-serif; background: var(--off-white); color: var(--text-primary); min-height: 100vh; }
        
        .page { padding: 32px 36px; max-width: 1200px; margin: 0 auto; animation: fadeIn .4s ease; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        
        .page-header { margin-bottom: 24px; }
        .page-title { font-size: 1.5rem; font-weight: 700; letter-spacing: -.03em; }
        .page-subtitle { font-size: .875rem; color: var(--text-secondary); margin-top: 4px; }

        .tab-nav { display: flex; gap: 12px; margin-bottom: 24px; border-bottom: 2px solid var(--border); padding-bottom: 12px; }
        .tab-btn { background: none; border: none; font-family: 'Sora', sans-serif; font-size: .95rem; font-weight: 600; color: var(--text-secondary); padding: 8px 16px; cursor: pointer; border-radius: var(--radius-sm); transition: .2s; }
        .tab-btn:hover { background: var(--border); color: var(--text-primary); }
        .tab-btn.active { background: var(--accent); color: var(--white); }
        .tab-pane { display: none; animation: fadeIn .3s ease; }
        .tab-pane.active { display: block; }

        .card { background: var(--white); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow-sm); overflow: visible; margin-bottom: 24px; }
        .card-head { padding: 18px 22px; border-bottom: 1px solid var(--border); background: #fafafa; border-radius: var(--radius) var(--radius) 0 0; font-weight: 700; font-size: 1rem; display: flex; align-items: center; gap: 8px;}
        .card-body { padding: 24px; }

        .form-control { width: 100%; padding: 12px 16px; border: 1.5px solid var(--border); border-radius: var(--radius-sm); font-family: 'Sora', sans-serif; font-size: .9rem; outline: none; transition: .2s; }
        .form-control:focus { border-color: var(--info); }
        .form-control:not(:disabled) { background-color: #ffffff; }
        .form-control:disabled { background-color: var(--border); cursor: not-allowed; opacity: 0.7; }
        
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 12px 24px; font-family: 'Sora', sans-serif; font-size: .9rem; font-weight: 600; border-radius: var(--radius-sm); cursor: pointer; transition: all .2s; border: none; }
        .btn-primary { background: var(--accent); color: var(--white); }
        .btn-success { background: var(--success); color: var(--white); }
        .btn-success:hover { background: #047857; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(5,150,105,.2); }
        .btn:disabled { opacity: 0.6; cursor: not-allowed; transform: none !important; box-shadow: none !important; }

        .stat-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-top: 20px; }
        .stat-box { border: 1.5px solid var(--border); border-radius: var(--radius-sm); padding: 16px; text-align: center; }
        .stat-label { font-size: .7rem; font-weight: 700; color: var(--text-secondary); text-transform: uppercase; letter-spacing: .05em; }
        .stat-value { font-size: 1.5rem; font-weight: 700; color: var(--text-primary); font-family: 'JetBrains Mono', monospace; margin-top: 8px; }
        .stat-box.pending { border-color: var(--warning-border); background: var(--warning-bg); }
        .stat-box.pending .stat-value { color: var(--warning); }
        .stat-box.other { border-color: #fca5a5; background: #fef2f2; }
        .stat-box.other .stat-value { color: #dc2626; }

        .search-wrap { position: relative; z-index: 50; }
        .search-dropdown { position: absolute; top: 100%; left: 0; right: 0; background: var(--white); border: 1.5px solid var(--info); border-top: none; border-radius: 0 0 var(--radius-sm) var(--radius-sm); box-shadow: var(--shadow-lg); max-height: 250px; overflow-y: auto; display: none; }
        .search-dropdown.open { display: block; }
        .search-result { padding: 12px 16px; cursor: pointer; border-bottom: 1px solid var(--border); }
        .search-result:hover { background: var(--info-bg); }
        .search-name { font-weight: 700; font-size: .9rem; }
        .search-meta { font-size: .75rem; color: var(--text-secondary); }

        .soa-receipt { border: 2px solid var(--border); border-radius: var(--radius-sm); padding: 32px; background: #fff; max-width: 600px; margin: 0 auto; box-shadow: var(--shadow-sm); }
        .soa-header { text-align: center; border-bottom: 2px dashed var(--border); padding-bottom: 16px; margin-bottom: 24px; }
        .soa-title { font-weight: 700; font-size: 1.25rem; letter-spacing: 1px; text-transform: uppercase; }
        .soa-row { display: flex; justify-content: space-between; margin-bottom: 12px; font-size: .9rem; }
        .soa-row.category { font-weight: 700; color: var(--info); margin-top: 24px; font-size: .8rem; text-transform: uppercase; border-bottom: 1px solid var(--border); padding-bottom: 4px; }
        .soa-row .amount { font-family: 'JetBrains Mono', monospace; font-weight: 600; }
        .soa-total { display: flex; justify-content: space-between; border-top: 2px solid var(--text-primary); padding-top: 16px; margin-top: 24px; font-weight: 700; font-size: 1.25rem; }
        .soa-total .amount { color: var(--error); font-family: 'JetBrains Mono', monospace; }
        
        .alert { padding: 16px; border-radius: var(--radius-sm); margin-bottom: 20px; font-weight: 600; display: none; }
        .alert.success { background: var(--success-bg); color: var(--success); border: 1px solid var(--success-border); display: block; }
        .alert.error { background: var(--error-bg); color: var(--error); border: 1px solid var(--error-border); display: block; }
        .spinner { display: inline-block; width: 16px; height: 16px; border: 2px solid #fff; border-top-color: transparent; border-radius: 50%; animation: spin .6s linear infinite; display: none; }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* Print Styles to force background colors */
        @media print {
            * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
            body { background: white; }
            .main-content, .page-header, .tab-nav, .search-wrap, button { display: none !important; }
            .card { border: none; box-shadow: none; margin: 0; padding: 0; }
            .card-head { display: none; }
            .soa-receipt { border: none; box-shadow: none; max-width: 100%; margin: 0; padding: 0; }
        }

        .required-note {
            margin-top: 8px;
            padding: 10px 12px;
            border-radius: var(--radius-sm);
            background: var(--warning-bg);
            color: var(--warning);
            border: 1px solid #fde68a;
            font-size: .78rem;
            line-height: 1.5;
            font-weight: 600;
        }

        .required-label::after {
            content: ' *';
            color: var(--error);
            font-weight: 800;
        }

        .posting-org-card {
            margin-bottom: 20px;
            padding: 16px;
            border: 1.5px solid var(--border);
            border-radius: var(--radius);
            background: #fff;
        }

        .posting-org-head {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            align-items: flex-start;
            margin-bottom: 10px;
        }

        .posting-org-title {
            font-size: .82rem;
            font-weight: 800;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .posting-org-subtitle {
            font-size: .78rem;
            color: var(--text-secondary);
            margin-top: 3px;
            line-height: 1.45;
        }

        .date-control-wrap {
            position: relative;
        }

        .date-control-wrap .form-control {
            padding-right: 42px;
            background: #fff;
            cursor: pointer;
        }

        .date-icon {
            position: absolute;
            right: 13px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            pointer-events: none;
            display: inline-flex;
            align-items: center;
        }

        .flatpickr-calendar {
            font-family: 'Sora', sans-serif !important;
            border: 1px solid var(--border) !important;
            border-radius: 14px !important;
            box-shadow: 0 18px 45px rgba(15, 23, 42, .18) !important;
            overflow: hidden !important;
            z-index: 999999 !important;
        }

        .flatpickr-calendar.open {
            z-index: 999999 !important;
        }

        .flatpickr-months,
        .flatpickr-weekdays {
            background: var(--accent) !important;
        }

        .flatpickr-month,
        .flatpickr-current-month,
        .flatpickr-current-month input.cur-year,
        .flatpickr-current-month .flatpickr-monthDropdown-months,
        .flatpickr-prev-month,
        .flatpickr-next-month,
        .flatpickr-weekday {
            color: #fff !important;
            fill: #fff !important;
        }

        .flatpickr-current-month .flatpickr-monthDropdown-months {
            background: var(--accent) !important;
            font-family: 'Sora', sans-serif !important;
            font-weight: 700 !important;
        }

        .flatpickr-current-month .flatpickr-monthDropdown-months option {
            background: var(--accent);
            color: #fff;
        }

        .flatpickr-day {
            border-radius: 8px !important;
            font-family: 'Sora', sans-serif !important;
        }

        .flatpickr-day.today {
            border-color: var(--accent) !important;
            color: var(--accent) !important;
            font-weight: 800;
        }

        .flatpickr-day.selected,
        .flatpickr-day.selected:hover {
            background: var(--accent) !important;
            border-color: var(--accent) !important;
            color: #fff !important;
        }

        .form-control.is-invalid,
        .org-unit-select:invalid {
            border-color: var(--error) !important;
            box-shadow: 0 0 0 3px rgba(220, 38, 38, .08);
        }

        @media (max-width: 768px) {
            .posting-org-card .org-unit-grid {
                grid-template-columns: 1fr !important;
            }
        }


        /* ================================================================
           Formal Statement of Account document — A4 landscape
           ================================================================ */
        .soa-receipt {
            width: 100%;
            max-width: 1120px;
            min-height: 720px;
            margin: 0 auto;
            padding: 38px 46px 30px;
            background: #fff;
            border: 1px solid #d9dde4;
            border-radius: 2px;
            box-shadow: 0 12px 35px rgba(15, 23, 42, .10);
            color: #111827;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 12px;
            line-height: 1.32;
        }

        .soa-document-header {
            display: grid;
            grid-template-columns: minmax(0, 1.32fr) minmax(280px, .68fr);
            gap: 44px;
            align-items: start;
            margin-bottom: 22px;
        }

        .soa-title {
            margin: 0 0 26px;
            color: #111827;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 24px;
            font-weight: 800;
            letter-spacing: .01em;
            text-align: left;
            text-transform: uppercase;
        }

        .soa-scope {
            margin: -19px 0 18px;
            color: #6b7280;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .soa-customer-name {
            margin-bottom: 3px;
            font-size: 13px;
            font-weight: 800;
            text-transform: uppercase;
        }

        .soa-customer-address,
        .soa-customer-contact {
            min-height: 16px;
            color: #374151;
            font-size: 11px;
        }

        .soa-meta-grid {
            display: grid;
            grid-template-columns: max-content minmax(120px, 1fr);
            gap: 4px 12px;
            margin-top: 22px;
            max-width: 460px;
            font-size: 11px;
        }

        .soa-meta-label { color: #374151; }
        .soa-meta-value { color: #111827; font-weight: 700; }

        .soa-company-block {
            display: grid;
            grid-template-columns: 88px minmax(0, 1fr);
            gap: 13px;
            align-items: start;
            justify-self: stretch;
            text-align: right;
        }

        .soa-company-logo-wrap {
            display: flex;
            min-height: 78px;
            align-items: center;
            justify-content: center;
        }

        .soa-company-logo {
            display: none;
            width: 82px;
            max-height: 82px;
            object-fit: contain;
        }

        .soa-company-name {
            margin-bottom: 4px;
            color: #111827;
            font-size: 12px;
            font-weight: 800;
            line-height: 1.18;
            text-transform: uppercase;
        }

        .soa-company-detail {
            color: #374151;
            font-size: 10px;
            line-height: 1.34;
            overflow-wrap: anywhere;
        }

        .soa-ledger-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            font-size: 10.5px;
        }

        .soa-ledger-table col.date { width: 12%; }
        .soa-ledger-table col.reference { width: 14%; }
        .soa-ledger-table col.description { width: 38%; }
        .soa-ledger-table col.money { width: 12%; }

        .soa-ledger-table thead th {
            padding: 5px 8px;
            border: 0;
            background: #e5e7eb;
            color: #111827;
            font-size: 10px;
            font-weight: 800;
            text-align: left;
        }

        .soa-ledger-table thead th.soa-money,
        .soa-ledger-table tbody td.soa-money { text-align: right; }

        .soa-ledger-table tbody td {
            padding: 5px 8px;
            border: 0;
            vertical-align: top;
        }

        .soa-ledger-table tbody tr.soa-opening-row td {
            padding-top: 4px;
            padding-bottom: 7px;
            color: #4b5563;
            font-style: italic;
        }

        .soa-line-description { color: #111827; font-weight: 700; }
        .soa-line-meta { margin-top: 1px; color: #6b7280; font-size: 9px; }
        .soa-money { white-space: nowrap; font-variant-numeric: tabular-nums; }

        .soa-empty-row td {
            padding: 24px 8px !important;
            color: #6b7280;
            font-weight: 700;
            text-align: center;
        }

        .soa-summary {
            width: 40%;
            min-width: 310px;
            margin: 18px 0 0 auto;
            font-size: 11px;
        }

        .soa-summary-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 140px;
            gap: 16px;
            align-items: center;
            padding: 4px 8px;
        }

        .soa-summary-row strong:last-child {
            text-align: right;
            white-space: nowrap;
            font-variant-numeric: tabular-nums;
        }

        .soa-summary-row.overdue {
            border-bottom: 2px solid #111827;
            font-weight: 800;
        }

        .soa-notes {
            margin-top: 20px;
            color: #374151;
            font-size: 10px;
            line-height: 1.45;
        }

        .soa-notes p { margin: 0 0 8px; }

        .soa-upcoming {
            margin-top: 18px;
            padding-top: 10px;
            border-top: 1px solid #d1d5db;
        }

        .soa-upcoming-title {
            margin-bottom: 6px;
            color: #374151;
            font-size: 9.5px;
            font-weight: 800;
            letter-spacing: .06em;
            text-transform: uppercase;
        }

        .soa-upcoming-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9.5px;
        }

        .soa-upcoming-table th,
        .soa-upcoming-table td { padding: 4px 7px; text-align: left; }
        .soa-upcoming-table th { background: #f3f4f6; }
        .soa-upcoming-table th:last-child,
        .soa-upcoming-table td:last-child { text-align: right; }

        .soa-signatory-wrap {
            display: flex;
            justify-content: flex-end;
            margin-top: 26px;
            padding-right: 4%;
        }

        .soa-signatory {
            min-width: 270px;
            text-align: center;
        }

        .soa-signatory-name {
            min-height: 18px;
            color: #111827;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .02em;
            text-decoration: underline;
            text-transform: uppercase;
        }

        .soa-signatory-title {
            margin-top: 3px;
            color: #111827;
            font-size: 10px;
            font-weight: 700;
        }

        .soa-document-footer {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 18px;
            align-items: end;
            margin-top: 30px;
            color: #4b5563;
            font-size: 8px;
        }

        .soa-footer-company { text-align: center; }
        .soa-page-number { white-space: nowrap; font-size: 9px; }

        .soa-loading {
            padding: 30px 8px;
            color: #6b7280;
            font-weight: 700;
            text-align: center;
        }

        @media (max-width: 900px) {
            .soa-receipt { padding: 26px 22px; min-height: 0; }
            .soa-document-header { grid-template-columns: 1fr; gap: 22px; }
            .soa-company-block { justify-self: stretch; max-width: 440px; text-align: left; }
            .soa-summary { width: 100%; min-width: 0; }
        }

            /* the class of the posting sits in the same column of fields as the date and the term */
        .posting-class-row { margin: 4px 0 18px; max-width: 520px; }
        .posting-class-row .water-class-field { margin: 0; }
        .main-content .posting-class-row .water-class-label { font-size: .8rem; font-weight: 700; color: var(--text-secondary); margin-bottom: 8px; }
</style>
<style id="print-readability-fix">
@page { size: A4 landscape; margin: 10mm 12mm; }
@media print {
    * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
    html, body { width: 100%; min-height: 0; background: #fff !important; }
    body * { visibility: hidden !important; }
    .main-content, .page, #tab-print, #soaContainer, .card, .card-body { display: block !important; }
    #tab-post, .page-header, .tab-nav, .card-head, .soa-screen-actions { display: none !important; }
    #printableSOA, #printableSOA * { visibility: visible !important; }
    #printableSOA {
        position: absolute !important;
        inset: 0 auto auto 0 !important;
        width: 100% !important;
        max-width: none !important;
        min-height: 0 !important;
        margin: 0 !important;
        padding: 0 !important;
        border: 0 !important;
        border-radius: 0 !important;
        box-shadow: none !important;
        color: #000 !important;
        background: #fff !important;
        font-family: Arial, Helvetica, sans-serif !important;
    }
    #printableSOA .soa-title { font-size: 18pt !important; }
    #printableSOA .soa-ledger-table { font-size: 8.5pt !important; }
    #printableSOA .soa-ledger-table thead { display: table-header-group; }
    #printableSOA .soa-ledger-table tr,
    #printableSOA .soa-summary,
    #printableSOA .soa-notes,
    #printableSOA .soa-upcoming,
    #printableSOA .soa-signatory-wrap,
    #printableSOA .soa-document-footer {
        break-inside: avoid;
        page-break-inside: avoid;
    }
    #printableSOA .soa-company-logo { max-height: 20mm !important; }
    .soa-screen-actions { display: none !important; }
}
</style>
</head>
<body>

<?php require_once '../common/navbar.php'; ?>

<div class="main-content">
    <main class="page">
        
        <div class="page-header">
            <a href="<?= $soaMode ? 'reports_management.php' : 'billing_management.php' ?>" style="display:inline-flex; align-items:center; gap:6px; color:var(--text-secondary); text-decoration:none; font-size:.8rem; font-weight:600; margin-bottom:8px;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="14" height="14"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                <?= $soaMode ? 'Back to Reports Management' : 'Back to Billing Management' ?>
            </a>
            <h1 class="page-title"><?= $soaMode ? 'Generate Statement of Account' : 'Post Billing' ?></h1>
            <p class="page-subtitle">
                <?= $soaMode
                    ? 'Generate and print a member Statement of Account from the Reports Management module.'
                    : 'Post only pending water-reading drafts. Due date is calculated as the reading period end date plus the selected payment term.' ?>
            </p>
        </div>

        <div id="alertBox" class="alert"></div>
        <?php if ($loadErrors): ?>
            <div class="alert error">The page could not load required database data: <?= e(implode(' ', array_unique($loadErrors))) ?> <?= $soaMode ? 'Statement generation' : 'Posting' ?> is disabled until the database problem is corrected.</div>
        <?php endif; ?>

        <div id="tab-post" class="tab-pane <?= $soaMode ? '' : 'active' ?>">
            <div class="card">
                <div class="card-head">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="18" height="18"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                    Post Billing / Make Bills
                </div>
                <div class="card-body">
                    
                    <div style="display:flex; gap:16px; margin-bottom: 24px; align-items: flex-end; flex-wrap: wrap;">
                        <div style="flex:1; min-width: 250px;">
                            <label style="font-size:.8rem; font-weight:700; color:var(--text-secondary); display:block; margin-bottom:8px;">1. Posting Scope</label>
                            <select id="postFilter" class="form-control" onchange="handlePostFilterChange()">
                                <option value="">— Select posting scope —</option>
                                <option value="all">Post Water Bills + Show Active Fees</option>
                                <option value="water">Post Water Bills Only</option>
                                <option value="other">Show Other Fees Only</option>
                            </select>
                        </div>
                        <div style="flex:1; min-width: 250px;">
                            <label style="font-size:.8rem; font-weight:700; color:var(--text-secondary); display:block; margin-bottom:8px;">2. Select Period to Post</label>
                            <select id="periodSelect" class="form-control" onchange="handlePostPeriodChange()" disabled>
                                <option value="">— Select an open reading period —</option>
                                <?php foreach ($openPeriods as $p):
                                    $pendingCount = (int)($p['pending_count'] ?? 0);
                                    $postedCount = (int)($p['posted_count'] ?? 0);
                                    $readingCount = (int)($p['reading_count'] ?? 0);
                                    if ($readingCount === 0) {
                                        $periodStatus = 'No readings entered';
                                    } elseif ($pendingCount === 0) {
                                        $periodStatus = 'Fully posted — ' . $postedCount . ' bill(s)';
                                    } else {
                                        $periodStatus = $pendingCount . ' pending';
                                        if ($postedCount > 0) $periodStatus .= ' • ' . $postedCount . ' already posted';
                                    }
                                ?>
                                    <option value="<?= (int)$p['period_id'] ?>"
                                            data-end="<?= e(substr((string)$p['end_date'], 0, 10)) ?>"
                                            data-pending="<?= $pendingCount ?>"
                                            data-posted="<?= $postedCount ?>"
                                            <?= $pendingCount === 0 ? 'disabled' : '' ?>>
                                        <?= e($p['bp_code']) ?> (Ends: <?= e(date('M j, Y', strtotime((string)$p['end_date']))) ?>) — <?= e($periodStatus) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div style="flex:1; min-width: 180px;">
                            <label style="font-size:.8rem; font-weight:700; color:var(--text-secondary); display:block; margin-bottom:8px;">3. Bill Date / Transaction Date</label>
                            <div class="date-control-wrap">
                                <input type="text" id="postBillingDate" class="form-control js-date-input" value="<?= date('Y-m-d') ?>" autocomplete="off" required>
                                <span class="date-icon">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="17" height="17"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                </span>
                            </div>
                        </div>
                        <div style="flex:1; min-width: 140px;">
                            <label style="font-size:.8rem; font-weight:700; color:var(--text-secondary); display:block; margin-bottom:8px;">4. Term (Days)</label>
                            <input type="number" id="postTermDays" class="form-control" value="15" min="1" oninput="syncPostDueFromTerm()" required>
                        </div>
                        <div style="flex:1; min-width: 180px;">
                            <label style="font-size:.8rem; font-weight:700; color:var(--text-secondary); display:block; margin-bottom:8px;">5. Due Date (Reading Period End + Term)</label>
                            <div class="date-control-wrap">
                                <input type="text" id="postDueDate" class="form-control" value="" autocomplete="off" readonly required aria-readonly="true">
                                <span class="date-icon">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="17" height="17"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                </span>
                            </div>
                        </div>
                    </div>


                    <div class="posting-class-row">
                        <?= render_water_class_field(null, true, 'classId', 'class_id', 'form-control org-unit-select', '6. Class of the posting') ?>
                    </div>

                    <div id="postingSummary" style="display:none;">
                        <div class="stat-grid">
                            <div class="stat-box pending">
                                <div class="stat-label">Pending Water Drafts</div>
                                <div class="stat-value" id="statDraft">0</div>
                            </div>
                            <div class="stat-box other">
                                <div class="stat-label">Other Active Fees Due</div>
                                <div class="stat-value" id="statOther">0</div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-label">Already Posted (Excluded)</div>
                                <div class="stat-value" id="statBilled" style="color:var(--success);">0</div>
                            </div>
                            <div class="stat-box" style="background:#fafafa;">
                                <div class="stat-label">Visible Unposted / Reference Items</div>
                                <div class="stat-value" id="statTotal" style="color:var(--text-secondary);">0</div>
                            </div>
                        </div>

                        <div id="postingStatusNotice" class="required-note" style="margin-top:16px;background:var(--info-bg);color:var(--info);border-color:#bfdbfe;">
                            Only unposted water bills are listed for posting. Already-posted bills are excluded from the action table and cannot be posted again.
                        </div>

                        <div id="draftListContainer" style="margin-top: 24px; max-height: 400px; overflow-y: auto; overflow-x: auto; border: 1px solid var(--border); border-radius: var(--radius-sm); display: none;">
                            <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: .85rem;">
                                <thead style="background: var(--off-white); position: sticky; top: 0; z-index: 10;">
                                    <tr>
                                        <th style="padding: 10px 14px; border-bottom: 2px solid var(--border); color:var(--text-secondary);">Member Name</th>
                                        <th style="padding: 10px 14px; border-bottom: 2px solid var(--border); color:var(--text-secondary);">Charge Type</th>
                                        <th style="padding: 10px 14px; border-bottom: 2px solid var(--border); color:var(--text-secondary);">Description</th>
                                        <th style="padding: 10px 14px; border-bottom: 2px solid var(--border); color:var(--text-secondary);">Due Date</th>
                                        <th style="padding: 10px 14px; border-bottom: 2px solid var(--border); text-align: right; color:var(--info);">Amount</th>
                                    </tr>
                                </thead>
                                <tbody id="draftTableBody"></tbody>
                            </table>
                        </div>

                        <div style="margin-top: 32px; border-top: 1px solid var(--border); padding-top: 24px;">
                            <button class="btn btn-success" id="btnPost" onclick="postBilling()">
                                <span class="spinner" id="spinnerPost"></span>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="18" height="18"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                Post Filtered Bills
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div id="tab-print" class="tab-pane <?= $soaMode ? 'active' : '' ?>">
            <div class="card">
                <div class="card-head">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="18" height="18"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
                    Generate Statement of Account
                </div>
                <div class="card-body">
                    
                    <div style="display:flex; gap:16px; margin-bottom: 32px; align-items: flex-end; flex-wrap: wrap;">
                        <div style="flex:1; min-width: 220px;">
                            <label style="font-size:.8rem; font-weight:700; color:var(--text-secondary); display:block; margin-bottom:8px;">1. Statement Type</label>
                            <select id="printFilter" class="form-control" onchange="handleTypeChange()">
                                <option value="">— Select Statement Type —</option>
                                <option value="all">Complete SOA (Water + All Fees)</option>
                                <option value="water">Water Bill Only</option>
                                <option value="other">Other Fees Only (Installment, Service Charges, etc.)</option>
                            </select>
                        </div>
                        <div style="flex:1; min-width: 220px;">
                            <label style="font-size:.8rem; font-weight:700; color:var(--text-secondary); display:block; margin-bottom:8px;">2. Reading Period</label>
                            <select id="soaPeriod" class="form-control" onchange="handlePeriodChange()" disabled>
                                <option value="">— Select reading period —</option>
                                <?php foreach ($allPeriods as $p): ?>
                                    <option value="<?= (int)$p['period_id'] ?>" data-end="<?= e(substr((string)$p['end_date'], 0, 10)) ?>"><?= e($p['bp_code']) ?><?= $p['status'] === 'closed' ? ' (Closed)' : '' ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div style="flex:1; min-width: 220px;">
                            <label style="font-size:.8rem; font-weight:700; color:var(--text-secondary); display:block; margin-bottom:8px;">3. Statement Date</label>
                            <div class="date-control-wrap">
                                <input type="text" id="statementDate" class="form-control js-date-input" value="<?= date('Y-m-d') ?>" autocomplete="off" onchange="triggerRegenerate()" required>
                                <span class="date-icon">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="17" height="17"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                </span>
                            </div>
                        </div>
                        <div style="flex:2; min-width: 250px;">
                            <label style="font-size:.8rem; font-weight:700; color:var(--text-secondary); display:block; margin-bottom:8px;">4. Search Member</label>
                            <div class="search-wrap">
                                <input type="text" id="memberSearch" class="form-control" placeholder="Search by name or account number..." autocomplete="off" disabled data-inc-list="#searchDropdown" data-inc-item=".search-result[data-action]">
                                <div class="search-dropdown" id="searchDropdown"></div>
                            </div>
                        </div>
                    </div>

                    <div id="soaContainer" style="display:none;">
                        <section class="soa-receipt" id="printableSOA" aria-label="Printable Statement of Account">
                            <header class="soa-document-header">
                                <div>
                                    <h2 class="soa-title" id="soaDynamicTitle">Statement of Account</h2>
                                    <div class="soa-scope" id="soaStatementScope">Complete outstanding account</div>

                                    <div class="soa-customer-name" id="soaCustomerName">Customer Name</div>
                                    <div class="soa-customer-address" id="soaCustomerAddress">Customer Address</div>
                                    <div class="soa-customer-contact" id="soaCustomerContact"></div>

                                    <div class="soa-meta-grid">
                                        <span class="soa-meta-label">Statement Date:</span>
                                        <strong class="soa-meta-value" id="soaDate"><?= date('Y-m-d') ?></strong>

                                        <span class="soa-meta-label">Terms:</span>
                                        <strong class="soa-meta-value" id="soaTerms">See individual due dates</strong>

                                        <span class="soa-meta-label">Account Reference:</span>
                                        <strong class="soa-meta-value" id="soaAcctRef">—</strong>

                                        <span class="soa-meta-label">Billing Period:</span>
                                        <strong class="soa-meta-value" id="soaPeriodLabel">—</strong>
                                    </div>
                                </div>

                                <div class="soa-company-block">
                                    <div class="soa-company-logo-wrap">
                                        <img class="soa-company-logo" id="soaCompanyLogo" alt="Company logo">
                                    </div>
                                    <div>
                                        <div class="soa-company-name" id="soaCompanyName"><?= e($soaIssuer['name']) ?></div>
                                        <div class="soa-company-detail" id="soaCompanyAddress"><?= e($soaIssuer['address']) ?></div>
                                        <div class="soa-company-detail" id="soaCompanyContact"><?= $soaIssuer['phone'] !== '' ? 'Telephone: ' . e($soaIssuer['phone']) : '' ?></div>
                                        <div class="soa-company-detail" id="soaCompanyOnline"></div>
                                        <div class="soa-company-detail" id="soaCompanyTin"></div>
                                    </div>
                                </div>
                            </header>

                            <div class="ui-table-scroll"><table class="soa-ledger-table" aria-label="Statement transactions">
                                <colgroup>
                                    <col class="date">
                                    <col class="reference">
                                    <col class="description">
                                    <col class="money">
                                    <col class="money">
                                    <col class="money">
                                </colgroup>
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Reference</th>
                                        <th>Description</th>
                                        <th class="soa-money">Debit</th>
                                        <th class="soa-money">Credit</th>
                                        <th class="soa-money">Balance</th>
                                    </tr>
                                </thead>
                                <tbody id="soaLedgerBody">
                                    <tr class="soa-empty-row"><td colspan="6">Select a member to generate the statement.</td></tr>
                                </tbody>
                            </table></div>

                            <div class="soa-summary">
                                <div class="soa-summary-row">
                                    <strong>Current Balance:</strong>
                                    <strong id="soaCurrentBalance">₱ 0.00</strong>
                                </div>
                                <div class="soa-summary-row overdue">
                                    <strong>Amount Overdue:</strong>
                                    <strong id="soaAmountOverdue">₱ 0.00</strong>
                                </div>
                            </div>

                            <div class="soa-notes">
                                <p id="soaSupportLine">For questions about this statement, contact <?= e($soaIssuer['name']) ?>.</p>
                                <p id="soaPaymentInstruction">Please settle your account at the <?= e($soaIssuer['name']) ?> collection office and present your account reference.</p>
                            </div>

                            <div id="upcomingSection"></div>

                            <div class="soa-signatory-wrap" id="soaGeneralManagerBlock">
                                <div class="soa-signatory">
                                    <div class="soa-signatory-name" id="soaGeneralManagerName"></div>
                                    <div class="soa-signatory-title" id="soaGeneralManagerTitle">General Manager</div>
                                </div>
                            </div>

                            <footer class="soa-document-footer">
                                <div class="soa-footer-company" id="soaFooterCompany">
                                    Registered Office: <?= e($soaIssuer['address']) ?>
                                </div>
                                <div class="soa-page-number">Page 1 of 1</div>
                            </footer>
                        </section>

                        <div class="soa-screen-actions" style="text-align:center; margin-top:24px;">
                            <button class="btn btn-primary" onclick="printSOA()">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="18" height="18"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
                                Print Statement
                            </button>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </main>
</div>

<script src="../../assets/vendor/flatpickr/flatpickr.min.js"></script>
<script>
    const SOA_ISSUER = <?= json_encode(['name' => $soaIssuer['name'], 'address' => $soaIssuer['address']], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
    let activeMemberId = null;
    let activeMemberName = null;
    
    // RESTORED: The missing search variables!
    let searchTimer = null;
    const searchInput = document.getElementById('memberSearch');
    const searchDropdown = document.getElementById('searchDropdown');

    const moneyToCents = value => {
        const raw = String(value ?? '0').trim();
        const match = raw.match(/^([+-]?)(\d+)(?:\.(\d{1,2}))?$/);
        if (!match) throw new Error('The server returned an invalid monetary amount.');
        const fraction = (match[3] || '').padEnd(2, '0');
        const cents = (BigInt(match[2]) * 100n) + BigInt(fraction || '0');
        return match[1] === '-' ? -cents : cents;
    };
    const fmtPesoCents = cents => {
        const negative = cents < 0n;
        const absolute = negative ? -cents : cents;
        const whole = (absolute / 100n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        const fraction = (absolute % 100n).toString().padStart(2, '0');
        return `₱ ${negative ? '-' : ''}${whole}.${fraction}`;
    };
    const fmtPeso = value => fmtPesoCents(moneyToCents(value));
    const e = str => String(str).replace(/[&<>'"]/g, match => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[match]));

    const datePickerInstances = {};

    function initDatePickers() {
        if (typeof flatpickr === 'undefined') return;

        document.querySelectorAll('.js-date-input').forEach(input => {
            datePickerInstances[input.id] = flatpickr(input, {
                dateFormat: 'Y-m-d',
                allowInput: true,
                disableMobile: true,
                clickOpens: true,
                onChange: function(selectedDates, dateStr) {
                    input.value = dateStr;

                    if (input.id === 'statementDate') {
                        triggerRegenerate();
                    }
                }
            });
        });
    }

    function setDateValue(id, value) {
        const input = document.getElementById(id);
        if (!input) return;

        if (datePickerInstances[id]) {
            datePickerInstances[id].setDate(value, false, 'Y-m-d');
        } else {
            input.value = value;
        }
    }

    function validatePostingOrgUnits() {
        const el = document.getElementById('classId');
        if (!el || !el.value || parseInt(el.value, 10) <= 0) {
            if (el) { el.classList.add('is-invalid'); el.focus(); }
            showAlert('error', 'Choose the class of the posting: it is required, and the journal entries carry it.');
            return false;
        }
        el.classList.remove('is-invalid');
        return true;
    }

    function selectedOptionText(id) {
        const el = document.getElementById(id);
        if (!el || !el.options || el.selectedIndex < 0) return '';
        return el.options[el.selectedIndex].text.trim();
    }

    document.addEventListener('DOMContentLoaded', function () {
        initDatePickers();

        ['classId'].forEach(id => {
            const el = document.getElementById(id);
            if (el) {
                el.addEventListener('change', function () {
                    if (this.value) this.classList.remove('is-invalid');
                });
            }
        });

        // Workspace visibility is decided by PHP. Post Billing does not expose an SOA tab;
        // Reports Management opens the SOA-only view with ?view=soa.
    });


    let alertHideTimer = null;

    function showAlert(type, msg, allowHtml = false, autoHide = true) {
        const box = document.getElementById('alertBox');
        if (!box) return;

        if (alertHideTimer) {
            clearTimeout(alertHideTimer);
            alertHideTimer = null;
        }

        box.className = `alert ${type}`;
        if (allowHtml) {
            box.innerHTML = msg;
        } else {
            box.textContent = msg;
        }

        box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

        if (autoHide) {
            alertHideTimer = setTimeout(() => {
                box.className = 'alert';
                box.textContent = '';
            }, 8000);
        }
    }
    
    function switchTab(tabId, triggerButton = null) {
        const targetPane = document.getElementById(tabId);
        if (!targetPane) return;

        document.querySelectorAll('.tab-pane').forEach(el => el.classList.remove('active'));
        document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
        targetPane.classList.add('active');

        const tabButton = triggerButton || document.querySelector(`[data-tab-target="${tabId}"]`);
        if (tabButton) tabButton.classList.add('active');
    }

    // ==========================================
    // POST BILLING LOGIC
    // ==========================================
    function handlePostFilterChange() {
        const filter = document.getElementById('postFilter').value;
        const periodSelect = document.getElementById('periodSelect');
        if (filter) {
            periodSelect.disabled = false;
            fetchPostingSummary();
        } else {
            periodSelect.disabled = true;
            periodSelect.value = '';
            setDateValue('postDueDate', '');
            document.getElementById('postingSummary').style.display = 'none';
        }
    }

    function parseIsoDateUtc(dateStr) {
        const match = String(dateStr || '').match(/^(\d{4})-(\d{2})-(\d{2})$/);
        if (!match) return null;
        return new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3])));
    }

    function formatIsoDateUtc(date) {
        return [
            date.getUTCFullYear(),
            String(date.getUTCMonth() + 1).padStart(2, '0'),
            String(date.getUTCDate()).padStart(2, '0')
        ].join('-');
    }

    function addDaysToDate(dateStr, days) {
        const d = parseIsoDateUtc(dateStr);
        if (!d) return '';
        d.setUTCDate(d.getUTCDate() + (parseInt(days, 10) || 0));
        return formatIsoDateUtc(d);
    }

    function daysBetweenDates(start, end) {
        const a = parseIsoDateUtc(start);
        const b = parseIsoDateUtc(end);
        if (!a || !b) return 0;
        return Math.round((b.getTime() - a.getTime()) / 86400000);
    }

    function getSelectedPostPeriodEnd() {
        const periodSelect = document.getElementById('periodSelect');
        if (!periodSelect || !periodSelect.value) return '';
        const selected = periodSelect.options[periodSelect.selectedIndex];
        return selected && selected.dataset.end ? selected.dataset.end : '';
    }

    /*
     * Due date rule:
     * - Bill Date / Transaction Date = date the billing is posted.
     * - Term in Days = number of days after the READING PERIOD END DATE.
     * - Due Date = Reading Period End Date + Term in Days.
     *
     * This is intentionally NOT Bill Date + Term.
     */
    function syncPostDueFromTerm() {
        const periodEnd = getSelectedPostPeriodEnd();
        const term = parseInt(document.getElementById('postTermDays')?.value || '0', 10) || 0;
        const dueDate = periodEnd && term > 0 ? addDaysToDate(periodEnd, term) : '';
        setDateValue('postDueDate', dueDate);
        document.querySelectorAll('.water-draft-due').forEach(cell => {
            cell.textContent = dueDate || 'Calculated when posted';
        });
    }

    function syncPostTermFromDue() {
        // Due date is authoritative and read-only: Period End Date + Term Days.
        syncPostDueFromTerm();
    }

    function handlePostPeriodChange() {
        syncPostDueFromTerm();
        fetchPostingSummary();
    }

    function fetchPostingSummary() {
        const periodId = document.getElementById('periodSelect').value;
        const filter = document.getElementById('postFilter').value;
        
        if (!periodId || !filter) { document.getElementById('postingSummary').style.display = 'none'; return; }

        fetch(`../process/postBilling.php?action=summary&period_id=${periodId}`, {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
            .then(async r => {
                const text = await r.text();
                let data;

                try {
                    data = JSON.parse(text);
                } catch (err) {
                    throw new Error('Posting summary returned invalid JSON: ' + text.substring(0, 250));
                }

                if (!data.success && data.error) {
                    throw new Error(data.error);
                }

                return data;
            })
            .then(data => {
                if (data.setup_errors && data.setup_errors.length > 0) {
                    let setupMsg = 'Some billing setup is incomplete: ' + e(data.setup_errors.join(' | '));
                    if (Array.isArray(data.setup_links) && data.setup_links.length > 0) {
                        setupMsg += '<div style="margin-top:8px; display:flex; flex-wrap:wrap; gap:8px;">';
                        data.setup_links.forEach(link => {
                            const href = e(link.url || '#');
                            const label = e(link.label || 'Open setup page');
                            setupMsg += `<a href="${href}" style="display:inline-flex; padding:7px 10px; border-radius:8px; background:#fff; color:#991b1b; text-decoration:none; font-weight:800; border:1px solid rgba(153,27,27,.25);">${label}</a>`;
                        });
                        setupMsg += '</div>';
                    }
                    showAlert('error', setupMsg, true, false);
                }

                let filteredCurrent = [];
                let filteredUpcoming = [];
                let waterDraftCount = 0;
                let otherFeesCount = 0;

                if (data.draft_list) {
                    filteredCurrent = data.draft_list.filter(item => {
                        if (filter === 'water' && item.type !== 'Water Bill') return false;
                        if (filter === 'other' && item.type === 'Water Bill') return false;
                        if (item.type === 'Water Bill') waterDraftCount++;
                        else otherFeesCount++;
                        return true;
                    });
                }

                if (data.upcoming_list) {
                    filteredUpcoming = data.upcoming_list.filter(item => {
                        if (filter === 'water') return false; 
                        return true;
                    });
                }

                document.getElementById('statTotal').textContent = waterDraftCount + otherFeesCount;
                document.getElementById('statDraft').textContent = waterDraftCount;
                document.getElementById('statOther').textContent = otherFeesCount;
                document.getElementById('statBilled').textContent = data.water_billed;
                const postingStatusNotice = document.getElementById('postingStatusNotice');
                if (postingStatusNotice) {
                    const postedCount = Number(data.water_billed || 0);
                    postingStatusNotice.textContent = postedCount > 0
                        ? `${postedCount} water bill(s) in this period are already posted and have been excluded. Only the ${waterDraftCount} pending bill(s) below can be posted.`
                        : `No water bills in this period have been posted yet. Only pending bills are listed below.`;
                }
                
                const tbody = document.getElementById('draftTableBody');
                const tableContainer = document.getElementById('draftListContainer');
                
                let html = '';

                // Render Current Dues
                if (filteredCurrent.length > 0) {
                    
                    let headerText = "Current Period Bills (Ready to Process)";
                    if (filter === 'water') headerText = "Draft Water Readings (To Be Confirmed / Locked)";
                    if (filter === 'other') headerText = "Active Other Fees (Informational Only — Not Posted Here)";
                    if (filter === 'all') headerText = "Water Drafts Ready to Post + Other Fees for Reference";

                    html += `<tr><td colspan="5" style="background:var(--border); padding: 8px 14px; font-weight:bold; font-size:0.8rem; text-transform:uppercase;">${headerText}</td></tr>`;
                    
                    html += filteredCurrent.map(r => {
                        let isWater = r.type === 'Water Bill';
                        
                        let bgStyle = isWater ? '' : 'background-color: var(--error-bg); border-left: 4px solid var(--error); border-bottom: 2px solid #fff;';
                        let textColor = isWater ? 'inherit' : 'var(--error)';
                        let subTextColor = isWater ? 'var(--text-secondary)' : 'var(--error)';
                        let amountDisplay = fmtPeso(r.amount || 0);

                        return `
                        <tr style="${bgStyle}">
                            <td style="padding: 10px 14px;">
                                <strong style="color:${textColor};">${e([r.lastname, r.firstname].filter(Boolean).join(', '))}</strong> 
                                <div style="font-size:.7rem; color:${subTextColor};">Acct: ${e(r.account_number || r.member_id)}</div>
                            </td>
                            <td style="padding: 10px 14px; color:${textColor}; font-weight:600;">${r.type}</td>
                            <td style="padding: 10px 14px; color:${textColor};">${e(r.details)}</td>
                            <td class="${isWater ? 'water-draft-due' : ''}" style="padding: 10px 14px; color:${textColor}; font-family: 'JetBrains Mono', monospace; font-size:.8rem;">${e(isWater ? (document.getElementById('postDueDate')?.value || 'Calculated when posted') : r.due_date)}</td>
                            <td style="padding: 10px 14px; text-align: right; color:${textColor}; font-family: 'JetBrains Mono', monospace;">${amountDisplay}</td>
                        </tr>`;
                    }).join('');
                }

                // Render Upcoming Dues
                if (filteredUpcoming.length > 0 && filter !== 'water') {
                    html += `<tr><td colspan="5" style="background:var(--border); padding: 8px 14px; font-weight:bold; font-size:0.8rem; text-transform:uppercase; margin-top:10px;">Upcoming / Advance Dues (Informational)</td></tr>`;
                    
                    html += filteredUpcoming.map(r => {
                        let bgStyle = 'background-color: var(--success-bg); border-left: 4px solid var(--success); border-bottom: 2px solid #fff;';
                        let textColor = 'var(--success)';
                        
                        return `
                        <tr style="${bgStyle}">
                            <td style="padding: 10px 14px;">
                                <strong style="color:${textColor};">${e([r.lastname, r.firstname].filter(Boolean).join(', '))}</strong> 
                                <div style="font-size:.7rem; color:${textColor};">Acct: ${e(r.account_number || r.member_id)}</div>
                            </td>
                            <td style="padding: 10px 14px; color:${textColor}; font-weight:600;">${r.type}</td>
                            <td style="padding: 10px 14px; color:${textColor};">${e(r.details)}</td>
                            <td style="padding: 10px 14px; color:${textColor}; font-family: 'JetBrains Mono', monospace; font-size:.8rem;">${e(r.due_date)}</td>
                            <td style="padding: 10px 14px; text-align: right; color:${textColor}; font-family: 'JetBrains Mono', monospace;">${fmtPeso(r.amount)}</td>
                        </tr>`;
                    }).join('');
                }

                if (html === '') {
                    html = `<tr><td colspan="5" style="text-align:center; padding:20px; color:var(--text-muted);">No items to process for this filter.</td></tr>`;
                }

                tbody.innerHTML = html;
                tableContainer.style.display = 'block';

                let btnText = "Post Selection";
                if(filter === 'water') btnText = "Post Water Bills";
                if(filter === 'other') btnText = "Other Fees Are View-Only";
                if(filter === 'all') btnText = "Post Water Bills Only (Fees Remain Unchanged)";
                
                document.getElementById('btnPost').innerHTML = `
                    <span class="spinner" id="spinnerPost"></span>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="18" height="18"><polyline points="20 6 9 17 4 12"></polyline></svg>
                    ${btnText}
                `;

                const hasSetupErrors = Array.isArray(data.setup_errors) && data.setup_errors.length > 0;
                document.getElementById('btnPost').disabled = (filter === 'other' || waterDraftCount === 0 || hasSetupErrors);
                document.getElementById('btnPost').title = hasSetupErrors
                    ? 'Posting is blocked until the billing setup/readings listed above are corrected.'
                    : '';
                document.getElementById('postingSummary').style.display = 'block';
            }).catch(err => {
                console.error(err);
                showAlert('error', err.message || 'Failed to load posting summary.');
                document.getElementById('postingSummary').style.display = 'none';
            });
    }

    function postBilling() {
        const periodId = document.getElementById('periodSelect').value;
        const filter = document.getElementById('postFilter').value;
        const billingDate = document.getElementById('postBillingDate').value;
        const termDays = parseInt(document.getElementById('postTermDays').value, 10) || 0;
        const periodEnd = getSelectedPostPeriodEnd();
        const calculatedDueDate = periodEnd && termDays > 0 ? addDaysToDate(periodEnd, termDays) : '';
        setDateValue('postDueDate', calculatedDueDate);
        const dueDate = calculatedDueDate;
        const btn = document.getElementById('btnPost');

        if (!periodId) { showAlert('error', 'Please select a reading period.'); return; }
        if (filter === 'other') {
            showAlert('info', 'Other receivables are informational on this page and are not posted or changed by the water-period posting command.');
            return;
        }
        if (!billingDate) { showAlert('error', 'Please select the Date of Transaction.'); return; }
        if (!dueDate) { showAlert('error', 'The due date could not be calculated. Select a period and enter a valid term.'); return; }
        if (termDays <= 0) { showAlert('error', 'Term in days must be greater than zero.'); return; }
        if (periodEnd && billingDate < periodEnd) {
            showAlert('error', 'The billing date cannot be earlier than the selected reading period end date.');
            return;
        }

        if (filter !== 'other' && !validatePostingOrgUnits()) return;

        const classText = selectedOptionText('classId');

        const latePosting = billingDate > dueDate;
        const lateWarning = latePosting
            ? `\n\nNOTE: The calculated due date (${dueDate}) has already passed. This is allowed for late/historical posting, and the posted bills will be immediately due/overdue according to the collection rules.`
            : '';

        const go = () => {
        btn.disabled = true;
        document.getElementById('spinnerPost').style.display = 'inline-block';

        fetch(`../process/postBilling.php`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({
                action: 'post',
                period_id: periodId,
                filter: filter,
                billing_date: billingDate,
                term_days: termDays,
                class_id: parseInt(document.getElementById('classId')?.value || '0', 10) || 0
            })
        }).then(async r => {
            const text = await r.text();
            try {
                return JSON.parse(text);
            } catch (err) {
                throw new Error('Post Billing returned invalid JSON: ' + text.substring(0, 250));
            }
        }).then(res => {
            btn.disabled = false;
            document.getElementById('spinnerPost').style.display = 'none';
            if (res.success) {
                showAlert('success', res.message || `Posted ${res.posted_count || 0} bill(s).`, false, false);
                fetchPostingSummary();
            } else {
                let msg = e(res.error || 'Posting failed.');
                if (res.hint) {
                    msg += `<br><span style="font-size:.78rem; font-weight:600;">${e(res.hint)}</span>`;
                }
                if (Array.isArray(res.setup_links) && res.setup_links.length > 0) {
                    msg += '<div style="margin-top:8px; display:flex; flex-wrap:wrap; gap:8px;">';
                    res.setup_links.forEach(link => {
                        const href = e(link.url || '#');
                        const label = e(link.label || 'Open setup page');
                        msg += `<a href="${href}" style="display:inline-flex; padding:7px 10px; border-radius:8px; background:#fff; color:#991b1b; text-decoration:none; font-weight:800; border:1px solid rgba(153,27,27,.25);">${label}</a>`;
                    });
                    msg += '</div>';
                }
                showAlert('error', msg, true, false);
            }
        }).catch((err) => {
            btn.disabled = false;
            document.getElementById('spinnerPost').style.display = 'none';
            showAlert('error', err.message || 'Network error while posting bills.', false, false);
        });
        };
        AppDialog.confirm(`Post the pending water bills with bill date ${billingDate} and due date ${dueDate} (${termDays} day(s) after the reading period end). Bills already posted are left out.${lateWarning}\n\nClass: ${classText}`,
            { title: 'Post the water bills', ok: 'Post the bills' }).then((yes) => { if (yes) go(); });
    }

    // ==========================================
    // TAB 2: SOA GENERATION LOGIC
    // ==========================================
    function handleTypeChange() {
        const filter = document.getElementById('printFilter').value;
        const periodDrop = document.getElementById('soaPeriod');
        if (filter) {
            periodDrop.disabled = false;
        } else {
            periodDrop.disabled = true;
            periodDrop.value = '';
            document.getElementById('memberSearch').disabled = true;
            document.getElementById('memberSearch').value = '';
            document.getElementById('soaContainer').style.display = 'none';
        }
        triggerRegenerate();
    }

    function handlePeriodChange() {
        const periodDrop = document.getElementById('soaPeriod').value;
        const searchBox = document.getElementById('memberSearch');
        if (periodDrop) {
            searchBox.disabled = false;
            searchBox.focus();
        } else {
            searchBox.disabled = true;
            searchBox.value = '';
            document.getElementById('soaContainer').style.display = 'none';
        }
        triggerRegenerate();
    }

    function triggerRegenerate() {
        if (activeMemberId && activeMemberName && document.getElementById('soaPeriod').value && document.getElementById('printFilter').value) {
            generateSOA(activeMemberId, activeMemberName);
        }
    }

    let memberSearchSeq = 0;   // a late answer to an older query is dropped
    searchInput.addEventListener('input', function() {
        clearTimeout(searchTimer);
        const q = this.value.trim();
        const seq = ++memberSearchSeq;
        const periodId = document.getElementById('soaPeriod').value;

        if (!periodId) { alert("Please select a reading period first."); this.value = ''; return; }
        if (q.length < 1) { searchDropdown.classList.remove('open'); return; }

        searchTimer = setTimeout(() => {
            fetch(`../process/searchMembers.php?context=generate_soa&q=${encodeURIComponent(q)}`)
                .then(async response => {
                    const data = await response.json().catch(() => ({}));
                    if (!response.ok || data.error) {
                        throw new Error(data.error || `Member search failed with HTTP ${response.status}.`);
                    }
                    return data;
                })
                .then(data => {
                    if (seq !== memberSearchSeq) return;
                    if(!data.length) {
                        searchDropdown.innerHTML = `<div style="padding:12px 16px; font-size:.85rem; color:var(--text-muted); text-align:center;">No members found.</div>`;
                    } else {
                        searchDropdown.innerHTML = data.map(m => `
                            <div class="search-result" data-action="generate-soa"
                                 data-member-id="${e(m.pkey)}" data-member-name="${e(m.full_name)}">
                                <div class="search-name">${e(m.full_name)}</div>
                                <div class="search-meta">${e([m.account_number ? 'Account no. ' + m.account_number : 'Account ID ' + m.pkey, ((m.housebldg ? m.housebldg + ' ' : '') + (m.street || '')).trim()].filter(Boolean).join(', '))}</div>
                            </div>`).join('');
                    }
                    searchDropdown.classList.add('open');
                }).catch(() => {
                    if (seq !== memberSearchSeq) return;
                    searchDropdown.innerHTML = `<div class="search-result" style="color:#b91c1c;cursor:default">Member search failed. Please retry or contact the administrator.</div>`;
                    searchDropdown.classList.add('open');
                });
        }, 160);
    });

    searchDropdown.addEventListener('click', event => {
        const result = event.target.closest('[data-action="generate-soa"]');
        if (!result) return;
        generateSOA(Number(result.dataset.memberId), result.dataset.memberName || 'Customer');
    });

    document.addEventListener('click', e => {
        if (!searchInput.contains(e.target) && !searchDropdown.contains(e.target)) searchDropdown.classList.remove('open');
    });

    function soaSetText(id, value, fallback = '—') {
        const element = document.getElementById(id);
        if (!element) return;
        const text = String(value ?? '').trim();
        element.textContent = text !== '' ? text : fallback;
    }

    function soaDateLabel(value) {
        const raw = String(value ?? '').trim();
        if (!/^\d{4}-\d{2}-\d{2}$/.test(raw)) return raw || '—';
        const [year, month, day] = raw.split('-');
        return `${day}/${month}/${year}`;
    }

    function soaMoneyCell(cents) {
        return fmtPesoCents(cents);
    }

    function soaFilterAllows(category, printFilter) {
        if (printFilter === 'water') return category === 'Water Bill';
        if (printFilter === 'other') return category !== 'Water Bill';
        return true;
    }

    function generateSOA(memberId, memberName) {
        searchDropdown.classList.remove('open');
        searchInput.value = '';

        activeMemberId = memberId;
        activeMemberName = memberName;

        const periodSel = document.getElementById('soaPeriod');
        const periodId = periodSel.value;
        const printFilter = document.getElementById('printFilter').value;
        const statementDate = document.getElementById('statementDate').value || new Date().toISOString().slice(0, 10);

        const scopeLabels = {
            all: 'Complete outstanding account',
            water: 'Water bill only',
            other: 'Other active fees only'
        };

        soaSetText('soaDynamicTitle', 'Statement of Account');
        soaSetText('soaStatementScope', scopeLabels[printFilter] || 'Statement of Account');
        soaSetText('soaCustomerName', memberName, 'Customer');
        soaSetText('soaDate', soaDateLabel(statementDate));
        soaSetText('soaAcctRef', memberId);

        document.getElementById('soaContainer').style.display = 'block';
        document.getElementById('soaLedgerBody').innerHTML = '<tr><td colspan="6" class="soa-loading">Generating statement...</td></tr>';
        document.getElementById('upcomingSection').innerHTML = '';
        soaSetText('soaCurrentBalance', '₱ 0.00');
        soaSetText('soaAmountOverdue', '₱ 0.00');

        const query = new URLSearchParams({
            member_id: String(memberId),
            period_id: String(periodId),
            statement_date: statementDate
        });

        fetch(`../process/getSoaData.php?${query.toString()}`)
            .then(async response => {
                const data = await response.json().catch(() => ({}));
                if (!response.ok || data.error || data.success === false) {
                    throw new Error(data.error || `Statement generation failed with HTTP ${response.status}.`);
                }
                return data;
            })
            .then(data => {
                const member = data.member || {};
                const company = data.company || {};
                const period = data.period || {};

                soaSetText('soaCustomerName', member.name || memberName, 'Customer');
                soaSetText('soaCustomerAddress', member.address, 'Address not recorded');

                const memberContacts = [];
                if (member.phone) memberContacts.push(`Tel: ${member.phone}`);
                if (member.email) memberContacts.push(`Email: ${member.email}`);
                if (member.zone) memberContacts.push(`Zone ${member.zone}`);
                soaSetText('soaCustomerContact', memberContacts.join('  •  '), '');

                soaSetText('soaDate', soaDateLabel(data.statement_date || statementDate));
                soaSetText('soaAcctRef', member.account_reference || memberId);
                const periodLabel = [period.code, period.start_date && period.end_date
                    ? `${soaDateLabel(period.start_date)} – ${soaDateLabel(period.end_date)}`
                    : ''].filter(Boolean).join('  •  ');
                soaSetText('soaPeriodLabel', periodLabel);

                let terms = 'See individual due dates';
                if (Number.isInteger(Number(data.term_days)) && Number(data.term_days) > 0) {
                    terms = `${Number(data.term_days)} day${Number(data.term_days) === 1 ? '' : 's'}`;
                    if (data.due_date) terms += `  •  Due ${soaDateLabel(data.due_date)}`;
                }
                soaSetText('soaTerms', terms);

                soaSetText('soaCompanyName', company.name, SOA_ISSUER.name);
                soaSetText('soaCompanyAddress', company.address, SOA_ISSUER.address);

                const companyPhones = [];
                if (company.telephone) companyPhones.push(`Telephone: ${company.telephone}`);
                if (company.mobile) companyPhones.push(`Mobile: ${company.mobile}`);
                soaSetText('soaCompanyContact', companyPhones.join('  •  '), '');

                const companyOnline = [];
                if (company.email) companyOnline.push(`Email: ${company.email}`);
                if (company.website) companyOnline.push(`Website: ${company.website}`);
                soaSetText('soaCompanyOnline', companyOnline.join('  •  '), '');
                soaSetText('soaCompanyTin', company.tin ? `TIN: ${company.tin}` : '', '');

                const logo = document.getElementById('soaCompanyLogo');
                if (company.logo_data_uri) {
                    logo.src = company.logo_data_uri;
                    logo.style.display = 'block';
                } else {
                    logo.removeAttribute('src');
                    logo.style.display = 'none';
                }

                const supportContact = company.telephone || company.mobile || company.email || '';
                soaSetText(
                    'soaSupportLine',
                    `For questions about this statement, contact ${company.name || SOA_ISSUER.name}${supportContact ? ` at ${supportContact}` : ''}.`
                );
                soaSetText(
                    'soaPaymentInstruction',
                    `Please settle your account at the ${company.name || SOA_ISSUER.name} collection office and present your account reference.`
                );
                soaSetText(
                    'soaFooterCompany',
                    `Registered Office: ${company.address || SOA_ISSUER.address}${company.name ? `  •  ${company.name}` : ''}`
                );

                const signatories = data.signatories || {};
                const generalManager = signatories.general_manager || {};
                const generalManagerName = String(generalManager.name || '').trim();
                const generalManagerTitle = String(generalManager.position || 'General Manager').trim() || 'General Manager';
                soaSetText('soaGeneralManagerName', generalManagerName, '');
                soaSetText('soaGeneralManagerTitle', generalManagerTitle, 'General Manager');
                const generalManagerBlock = document.getElementById('soaGeneralManagerBlock');
                if (generalManagerBlock) {
                    generalManagerBlock.style.display = generalManagerName === '' ? 'none' : 'flex';
                }

                const currentItems = [];
                Object.entries(data.current || {}).forEach(([category, items]) => {
                    if (!soaFilterAllows(category, printFilter)) return;
                    (Array.isArray(items) ? items : []).forEach(item => {
                        currentItems.push({ ...item, category: item.category || category });
                    });
                });

                currentItems.sort((left, right) => {
                    const dateCompare = String(left.date || left.due_date || '').localeCompare(String(right.date || right.due_date || ''));
                    if (dateCompare !== 0) return dateCompare;
                    return String(left.reference || '').localeCompare(String(right.reference || ''));
                });

                const periodStart = String(period.start_date || '');
                let openingBalanceCents = 0n;
                const movementItems = [];
                currentItems.forEach(item => {
                    const itemDate = String(item.date || item.due_date || '');
                    const amountCents = moneyToCents(item.amount ?? item.debit ?? '0.00');
                    if (periodStart && itemDate && itemDate < periodStart) {
                        openingBalanceCents += amountCents;
                    } else {
                        movementItems.push({ ...item, amountCents });
                    }
                });

                let runningBalanceCents = openingBalanceCents;
                let totalBalanceCents = openingBalanceCents;
                let overdueCents = 0n;

                currentItems.forEach(item => {
                    const amountCents = moneyToCents(item.amount ?? item.debit ?? '0.00');
                    totalBalanceCents += amountCents;
                    if (item.due_date && String(item.due_date) < String(data.statement_date || statementDate)) {
                        overdueCents += amountCents;
                    }
                });

                // openingBalanceCents was already included in currentItems above;
                // remove the duplicate addition from the total.
                totalBalanceCents -= openingBalanceCents;

                let ledgerHtml = `
                    <tr class="soa-opening-row">
                        <td></td>
                        <td></td>
                        <td>Balance brought forward:</td>
                        <td class="soa-money"></td>
                        <td class="soa-money"></td>
                        <td class="soa-money">${soaMoneyCell(openingBalanceCents)}</td>
                    </tr>`;

                if (movementItems.length === 0 && openingBalanceCents === 0n) {
                    ledgerHtml += '<tr class="soa-empty-row"><td colspan="6">No outstanding balance for this statement type.</td></tr>';
                } else {
                    movementItems.forEach(item => {
                        runningBalanceCents += item.amountCents;
                        const dueLabel = item.due_date ? `Due ${soaDateLabel(item.due_date)}` : 'No due date recorded';
                        ledgerHtml += `
                            <tr>
                                <td>${e(soaDateLabel(item.date || item.due_date))}</td>
                                <td>${e(item.reference || '—')}</td>
                                <td>
                                    <div class="soa-line-description">${e(item.description || item.category || 'Account charge')}</div>
                                    <div class="soa-line-meta">${e(item.category || '')}${item.category ? '  •  ' : ''}${e(dueLabel)}</div>
                                </td>
                                <td class="soa-money">${soaMoneyCell(item.amountCents)}</td>
                                <td class="soa-money">—</td>
                                <td class="soa-money">${soaMoneyCell(runningBalanceCents)}</td>
                            </tr>`;
                    });
                }

                document.getElementById('soaLedgerBody').innerHTML = ledgerHtml;
                soaSetText('soaCurrentBalance', soaMoneyCell(totalBalanceCents));
                soaSetText('soaAmountOverdue', soaMoneyCell(overdueCents));

                const upcomingItems = (Array.isArray(data.upcoming) ? data.upcoming : [])
                    .filter(item => soaFilterAllows(item.category || '', printFilter));

                if (upcomingItems.length > 0) {
                    let upcomingHtml = `
                        <section class="soa-upcoming">
                            <div class="soa-upcoming-title">Upcoming dues — not included in the current balance</div>
                            <div class="ui-table-scroll"><table class="soa-upcoming-table">
                                <thead><tr><th>Due Date</th><th>Reference</th><th>Description</th><th>Amount</th></tr></thead>
                                <tbody>`;
                    upcomingItems.forEach(item => {
                        upcomingHtml += `
                            <tr>
                                <td>${e(soaDateLabel(item.due_date || item.date))}</td>
                                <td>${e(item.reference || '—')}</td>
                                <td>${e(item.description || item.category || 'Upcoming charge')}</td>
                                <td>${fmtPeso(item.amount || '0.00')}</td>
                            </tr>`;
                    });
                    upcomingHtml += '</tbody></table></div></section>';
                    document.getElementById('upcomingSection').innerHTML = upcomingHtml;
                } else {
                    document.getElementById('upcomingSection').innerHTML = '';
                }
            })
            .catch(error => {
                console.error(error);
                document.getElementById('soaLedgerBody').innerHTML = '<tr class="soa-empty-row"><td colspan="6">The statement could not be generated.</td></tr>';
                showAlert('error', error.message || 'Failed to generate SOA.');
            });
    }

    function printSOA() {
        window.print();
    }
</script>
</body>
</html>
