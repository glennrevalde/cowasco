<?php
/* AUTH_GUARD_START */
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../process/accessControlHelpers.php';
$__authGuardPdo = getDB();
requirePageAccess($__authGuardPdo, basename(__FILE__, '.php'));
unset($__authGuardPdo);
/* AUTH_GUARD_END */

require_once __DIR__ . '/../database/config.php';
require_once '../database/config.php';


$pdo = getDB();


if (!function_exists('e')) {
    function e($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// ==========================================
// DYNAMIC DASHBOARD STATS FETCHING
// ==========================================
$totalMembers = 0;
$activeSubs = 0;
$unpaidAdHoc = 0;
$activeInstallments = 0;

$recentMembers = [];
$recentUsers = [];

if (isset($pdo)) {
    // 1. Total Active Members
    try {
        $totalMembers = $pdo->query("SELECT COUNT(*) FROM water_accounts WHERE status = 'A'")->fetchColumn();
    } catch (PDOException $e) {}

    // 2. Active Recurring Subscriptions
    try {
        $activeSubs = $pdo->query("SELECT COUNT(*) FROM recurring_subscriptions WHERE status = 'active'")->fetchColumn();
    } catch (PDOException $e) {}

    // 3. Unpaid Ad-Hoc Bills
    try {
        $unpaidAdHoc = $pdo->query("SELECT COUNT(*) FROM one_time_bills WHERE status = 'unpaid'")->fetchColumn();
    } catch (PDOException $e) {}

    // 4. Active Installment Contracts
    try {
        $activeInstallments = $pdo->query("SELECT COUNT(*) FROM installment_bills WHERE status = 'active'")->fetchColumn();
    } catch (PDOException $e) {}

    // 5. Fetch 5 Most Recently Added Members
    try {
        $recentMembers = $pdo->query("
            SELECT m.pkey, m.account_number, CONCAT_WS(', ', NULLIF(m.lastname, ''), NULLIF(m.firstname, '')) AS full_name, m.rc_id,
                   COALESCE(rc.rc_name, CONCAT('Rate Code ', m.rc_id)) AS rate_name,
                   COALESCE(ps.size_label, ps.size_code, 'Unspecified') AS pipe_size_name
            FROM water_accounts m
            LEFT JOIN rate_code rc ON rc.rc_id = m.rc_id
            LEFT JOIN water_pipe_sizes ps ON ps.pipe_size_id = m.pipe_size_id
            ORDER BY m.pkey DESC LIMIT 5
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}

    // 6. Fetch Recent Operators
    try {
        $recentUsers = $pdo->query("
            SELECT
                o.name AS username,
                COALESCE(NULLIF(o.description, ''), o.name) AS full_name,
                login_activity.last_login_at
            FROM operator o
            LEFT JOIN (
                SELECT user_id, MAX(created_at) AS last_login_at
                FROM water_audit_logs
                WHERE action = 'LOGIN'
                GROUP BY user_id
            ) login_activity ON login_activity.user_id = o.pkey
            WHERE COALESCE(o.deleted, 0) = 0
            ORDER BY COALESCE(login_activity.last_login_at, o.date_created) DESC, o.pkey DESC LIMIT 5
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}
}

// Last six calendar months of actual recorded collections.
$chartData = [];
$chartLabels = [];
$monthTotals = [];
try {
    $stmt = $pdo->query("SELECT DATE_FORMAT(payment_date, '%Y-%m') ym, COALESCE(SUM(total_paid),0) total FROM payment_transactions WHERE payment_date >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH), '%Y-%m-01') GROUP BY DATE_FORMAT(payment_date, '%Y-%m')");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $monthTotals[$row['ym']] = (float)$row['total'];
} catch (Throwable $e) {}
for ($i = 5; $i >= 0; $i--) {
    $date = new DateTime('first day of this month');
    $date->modify("-$i months");
    $key = $date->format('Y-m');
    $chartLabels[] = $date->format('M');
    $chartData[] = round($monthTotals[$key] ?? 0, 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../common/fonts.css">
    <title>Dashboard - <?= htmlspecialchars(app_name(), ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --white:          #ffffff;
            --off-white:      #f8f9fb;
            --border:         #e8eaed;
            --border-focus:   #1a1a2e;
            --text-primary:   #0f0f1a;
            --text-secondary: #6b7280;
            --text-muted:     #9ca3af;
            --accent:         #1a1a2e;
            --accent-mid:     #16213e;
            --accent-light:   #e8f0fe;
            --success:        #059669;
            --success-bg:     #ecfdf5;
            --error:          #dc2626;
            --error-bg:       #fef2f2;
            --warning:        #d97706;
            --warning-bg:     #fffbeb;
            --info:           #2563eb;
            --info-bg:        #eff6ff;
            --purple:         #7c3aed;
            --purple-bg:      #f3e8ff;
            --radius-sm:      8px;
            --radius:         14px;
            --radius-lg:      20px;
            --shadow-sm:      0 1px 3px rgba(0,0,0,.06), 0 1px 2px rgba(0,0,0,.04);
            --shadow:         0 4px 16px rgba(0,0,0,.08), 0 1px 4px rgba(0,0,0,.04);
            --shadow-lg:      0 20px 60px rgba(0,0,0,.10), 0 4px 20px rgba(0,0,0,.06);
            --transition:     0.2s cubic-bezier(0.4,0,0.2,1);
        }

        html { height: 100%; font-size: 16px; }

        body {
            font-family: 'Sora', sans-serif;
            background: var(--off-white);
            color: var(--text-primary);
            min-height: 100vh;
        }

        /* ============================
           MAIN CONTENT AREA
        ============================ */
        .page {
            padding: 36px 36px 48px;
            max-width: 1400px;
            margin: 0 auto;
            animation: fadeIn .4s ease both;
        }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }

        .page-header {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            margin-bottom: 32px;
            flex-wrap: wrap;
            gap: 16px;
        }
        .page-title {
            font-size: 1.625rem;
            font-weight: 700;
            letter-spacing: -.03em;
            color: var(--text-primary);
        }
        .page-subtitle {
            font-size: .875rem;
            color: var(--text-secondary);
            margin-top: 4px;
            font-weight: 400;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 10px 18px;
            font-family: inherit;
            font-size: .8125rem;
            font-weight: 600;
            border-radius: var(--radius-sm);
            border: none;
            cursor: pointer;
            text-decoration: none;
            transition: transform var(--transition), box-shadow var(--transition), background var(--transition);
            letter-spacing: -.01em;
        }
        .btn svg { width: 15px; height: 15px; }
        .btn-primary {
            background: var(--accent);
            color: var(--white);
            box-shadow: 0 2px 8px rgba(26,26,46,.2);
        }
        .btn-primary:hover { background: #0f0f1a; transform: translateY(-1px); box-shadow: 0 6px 20px rgba(26,26,46,.28); }

        /* ============================
           STATS GRID
        ============================ */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 28px;
        }

        .stat-card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 22px 24px;
            box-shadow: var(--shadow-sm);
            position: relative;
            overflow: hidden;
            transition: box-shadow var(--transition), transform var(--transition);
        }
        .stat-card:hover { box-shadow: var(--shadow); transform: translateY(-2px); }
        .stat-card::after {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 3px;
            border-radius: var(--radius) var(--radius) 0 0;
        }
        .stat-card.blue::after   { background: linear-gradient(90deg, #2563eb, #60a5fa); }
        .stat-card.green::after  { background: linear-gradient(90deg, #059669, #34d399); }
        .stat-card.amber::after  { background: linear-gradient(90deg, #d97706, #fbbf24); }
        .stat-card.purple::after { background: linear-gradient(90deg, #7c3aed, #a78bfa); }

        .stat-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 14px;
        }
        .stat-label {
            font-size: .75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .05em;
            color: var(--text-muted);
        }
        .stat-icon {
            width: 36px; height: 36px;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
        }
        .stat-icon svg { width: 18px; height: 18px; }
        .stat-icon.blue   { background: var(--info-bg);    color: var(--info); }
        .stat-icon.green  { background: var(--success-bg); color: var(--success); }
        .stat-icon.amber  { background: var(--warning-bg); color: var(--warning); }
        .stat-icon.purple { background: var(--purple-bg);  color: var(--purple); }

        .stat-value {
            font-size: 1.75rem;
            font-weight: 700;
            letter-spacing: -.04em;
            color: var(--text-primary);
            font-family: 'JetBrains Mono', monospace;
            line-height: 1;
            margin-bottom: 8px;
        }

        /* ============================
           QUICK ACTIONS
        ============================ */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .quick-action-card {
            background: var(--white);
            border: 1.5px solid var(--border);
            border-radius: var(--radius);
            padding: 20px 16px;
            text-align: center;
            text-decoration: none;
            color: var(--text-primary);
            transition: border-color var(--transition), box-shadow var(--transition), transform var(--transition);
            cursor: pointer;
        }
        .quick-action-card:hover {
            border-color: var(--accent);
            box-shadow: var(--shadow);
            transform: translateY(-2px);
        }
        .qa-icon {
            width: 42px; height: 42px;
            border-radius: 12px;
            background: var(--off-white);
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 12px;
            transition: background var(--transition);
        }
        .quick-action-card:hover .qa-icon { background: var(--accent-light); }
        .qa-icon svg { width: 20px; height: 20px; color: var(--accent); }
        .qa-label {
            font-size: .8rem;
            font-weight: 600;
            color: var(--text-primary);
        }
        .qa-desc {
            font-size: .72rem;
            color: var(--text-muted);
            margin-top: 3px;
        }

        /* ============================
           CONTENT GRID
        ============================ */
        .content-grid {
            display: grid;
            grid-template-columns: 1fr 400px;
            gap: 24px;
            margin-bottom: 24px;
        }

        /* ============================
           CARD BASE
        ============================ */
        .card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        .card-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 20px 24px;
            border-bottom: 1px solid var(--border);
        }
        .card-head-title {
            font-size: 1rem;
            font-weight: 700;
            letter-spacing: -.015em;
            color: var(--text-primary);
        }
        .card-head-sub {
            font-size: .75rem;
            color: var(--text-muted);
            margin-top: 2px;
        }
        .card-link {
            font-size: .8rem;
            color: var(--accent);
            text-decoration: none;
            font-weight: 600;
        }
        .card-link:hover { text-decoration: underline; }

        /* ============================
           REVENUE CHART AREA
        ============================ */
        .chart-wrap {
            padding: 24px;
            height: 240px;
            position: relative;
        }
        canvas#revenueChart { width: 100% !important; height: 100% !important; }

        /* ============================
           TABLE
        ============================ */
        .table-wrap { overflow-x: auto; flex: 1; }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: .8125rem;
        }
        thead th {
            padding: 12px 20px;
            text-align: left;
            font-size: .7rem;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: var(--text-secondary);
            background: var(--off-white);
            border-bottom: 1.5px solid var(--border);
        }
        tbody tr { border-bottom: 1px solid var(--border); transition: background var(--transition); }
        tbody tr:last-child { border-bottom: none; }
        tbody tr:hover { background: #fafbfc; }
        tbody td { padding: 14px 20px; color: var(--text-primary); vertical-align: middle; }

        .mono { font-family: 'JetBrains Mono', monospace; font-size: .8rem; }
        
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: .68rem;
            font-weight: 700;
            letter-spacing: .02em;
            text-transform: uppercase;
        }
        .badge-regular { background: var(--accent-light); color: var(--accent); }
        .badge-associate { background: #f3f4f6; color: #4b5563; border: 1px solid var(--border); }
        .badge-success { background: var(--success-bg); color: var(--success); }

        .avatar-sm {
            width: 32px; height: 32px; border-radius: 50%;
            background: var(--accent); color: var(--white);
            display: flex; align-items: center; justify-content: center;
            font-size: .7rem; font-weight: 700; flex-shrink: 0;
        }

        /* ============================
           RESPONSIVE
        ============================ */
        @media (max-width: 1024px) {
            .content-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 640px) {
            .page { padding: 24px 16px 48px; }
        }
    </style>

<style id="frontend-ui-refinement">
@media screen {
    :root {
        --radius-sm: 5px !important;
        --radius: 6px !important;
        --radius-lg: 8px !important;
        --shadow-sm: none !important;
        --shadow: none !important;
        --shadow-lg: none !important;
    }
    .page {
        max-width: 1320px !important;
        padding: 28px 28px 48px !important;
        animation: none !important;
    }
    .page-header {
        margin-bottom: 20px !important;
        padding-bottom: 16px !important;
        border-bottom: 1px solid var(--border) !important;
    }
    .page-title { font-size: 1.48rem !important; letter-spacing: -.02em !important; }
    .stats-grid {
        grid-template-columns: 1.35fr repeat(3, minmax(0, 1fr)) !important;
        gap: 12px !important;
        margin-bottom: 18px !important;
    }
    .stat-card {
        min-height: 122px !important;
        padding: 17px 18px !important;
        border-radius: 6px !important;
        box-shadow: none !important;
        overflow: visible !important;
        transition: background-color .15s ease !important;
    }
    .stat-card:hover { transform: none !important; box-shadow: none !important; background: #fbfcfd !important; }
    .stat-card::after {
        top: 14px !important;
        bottom: 14px !important;
        left: -1px !important;
        right: auto !important;
        width: 3px !important;
        height: auto !important;
        border-radius: 0 3px 3px 0 !important;
    }
    .stat-card.blue::after { background: #2563eb !important; }
    .stat-card.green::after { background: #059669 !important; }
    .stat-card.amber::after { background: #d97706 !important; }
    .stat-card.purple::after { background: #7c3aed !important; }
    .stat-top { margin-bottom: 12px !important; }
    .stat-icon { width: 30px !important; height: 30px !important; border-radius: 5px !important; }
    .stat-icon svg { width: 16px !important; height: 16px !important; }
    .stat-value { font-size: 1.58rem !important; margin-bottom: 6px !important; }
    .quick-actions {
        grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
        gap: 8px !important;
        margin-bottom: 18px !important;
    }
    .quick-action-card {
        min-height: 78px !important;
        padding: 13px 14px !important;
        display: grid !important;
        grid-template-columns: 34px minmax(0, 1fr) !important;
        grid-template-rows: auto auto !important;
        column-gap: 11px !important;
        text-align: left !important;
        border-width: 1px !important;
        border-radius: 6px !important;
        box-shadow: none !important;
        transition: background-color .15s ease !important;
    }
    .quick-action-card:hover,
    .quick-action-card:focus-visible {
        transform: none !important;
        box-shadow: none !important;
        border-color: #cbd5e1 !important;
        background: #f8fafc !important;
        outline: none !important;
    }
    .qa-icon {
        grid-row: 1 / 3 !important;
        width: 34px !important;
        height: 34px !important;
        margin: 0 !important;
        border-radius: 5px !important;
        align-self: center !important;
    }
    .quick-action-card:hover .qa-icon { background: #e5e7eb !important; }
    .qa-icon svg { width: 17px !important; height: 17px !important; }
    .qa-label { align-self: end !important; font-size: .79rem !important; }
    .qa-desc { align-self: start !important; margin-top: 1px !important; line-height: 1.35 !important; }
    .content-grid { grid-template-columns: minmax(0, 1fr) 360px !important; gap: 16px !important; }
    .content-grid > div { gap: 16px !important; }
    .card { border-radius: 6px !important; box-shadow: none !important; }
    .card-head { padding: 16px 18px !important; }
    .chart-wrap { padding: 18px !important; }
    .badge { border-radius: 4px !important; padding: 3px 7px !important; }
    .avatar-sm { border-radius: 5px !important; }
    .btn-primary { box-shadow: none !important; }
    .btn-primary:hover { transform: none !important; box-shadow: none !important; }
    @media (max-width: 1080px) {
        .stats-grid { grid-template-columns: repeat(2, minmax(0, 1fr)) !important; }
        .content-grid { grid-template-columns: 1fr !important; }
    }
    @media (max-width: 720px) {
        .page { padding: 22px 16px 40px !important; }
        .stats-grid, .quick-actions { grid-template-columns: 1fr !important; }
    }
}
@media (prefers-reduced-motion: reduce) {
    .page, .stat-card, .quick-action-card, .btn { animation: none !important; transition: none !important; }
}
</style>
</head>
<body>

<?php require_once '../common/navbar.php'; ?>

<div class="main-content">
<main class="page">

    <div class="page-header">
        <div>
            <div class="page-title">Good <?= (date('H') < 12 ? 'morning' : (date('H') < 17 ? 'afternoon' : 'evening')) ?>, <?= e(explode(' ', $_SESSION['full_name'])[0]) ?> </div>
            <div class="page-subtitle">Here is your operational overview for <?= date('F j, Y') ?>.</div>
        </div>
        <div style="display:flex; gap:10px;">
            <a href="bill_period.php" class="btn btn-primary">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                Manage Periods
            </a>
        </div>
    </div>

    <div class="stats-grid">
        <div class="stat-card blue">
            <div class="stat-top">
                <div class="stat-label">Active water accounts</div>
                <div class="stat-icon blue">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                </div>
            </div>
            <div class="stat-value"><?= number_format($totalMembers) ?></div>
        </div>

        <div class="stat-card green">
            <div class="stat-top">
                <div class="stat-label">Recurring Subs</div>
                <div class="stat-icon green">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
                </div>
            </div>
            <div class="stat-value"><?= number_format($activeSubs) ?></div>
        </div>

        <div class="stat-card amber">
            <div class="stat-top">
                <div class="stat-label">Unpaid Custom Bills</div>
                <div class="stat-icon amber">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                </div>
            </div>
            <div class="stat-value"><?= number_format($unpaidAdHoc) ?></div>
        </div>

        <div class="stat-card purple">
            <div class="stat-top">
                <div class="stat-label">Active Installments</div>
                <div class="stat-icon purple">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                </div>
            </div>
            <div class="stat-value"><?= number_format($activeInstallments) ?></div>
        </div>
    </div>

    <div class="quick-actions">
        <a href="reading_entry.php" class="quick-action-card">
            <div class="qa-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
            </div>
            <div class="qa-label">Enter Readings</div>
            <div class="qa-desc">Encode water consumption</div>
        </a>
        <a href="member_list.php" class="quick-action-card">
            <div class="qa-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="16" y1="11" x2="22" y2="11"/></svg>
            </div>
            <div class="qa-label">Open Water Account</div>
            <div class="qa-desc">Open a water-service account for a consumer</div>
        </a>
        <a href="one_time_billing.php" class="quick-action-card">
            <div class="qa-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
            </div>
            <div class="qa-label">Custom Billing</div>
            <div class="qa-desc">Issue one-time or service charges</div>
        </a>
        <a href="settings.php" class="quick-action-card">
            <div class="qa-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
            </div>
            <div class="qa-label">System Settings</div>
            <div class="qa-desc">Manage operators & configurations</div>
        </a>
    </div>

    <div class="content-grid">

        <div style="display:flex; flex-direction:column; gap:24px;">

            <div class="card">
                <div class="card-head">
                    <div>
                        <div class="card-head-title">System Metrics</div>
                        <div class="card-head-sub">Recorded collections for the last six calendar months</div>
                    </div>
                </div>
                <div class="chart-wrap">
                    <canvas id="revenueChart"></canvas>
                </div>
            </div>

            <div class="card" style="flex: 1;">
                <div class="card-head">
                    <div>
                        <div class="card-head-title">Recently Opened Accounts</div>
                        <div class="card-head-sub">The last 5 accounts opened</div>
                    </div>
                    <a href="member_list.php" class="card-link">View all →</a>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Account No.</th>
                                <th>Account Holder</th>
                                <th>Type</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentMembers)): ?>
                                <tr><td colspan="4" style="text-align:center; padding: 20px; color:var(--text-muted);">No water accounts yet.</td></tr>
                            <?php else: foreach ($recentMembers as $mem): 
                                $typeBadge = '<span class="badge badge-regular">' . e(($mem['rate_name'] ?? ('Rate Code ' . $mem['rc_id'])) . ' / ' . ($mem['pipe_size_name'] ?? 'Unspecified')) . '</span>';
                            ?>
                            <tr>
                                <td><span class="mono" style="color:var(--text-secondary);"><?= e($mem['account_number'] ?: $mem['pkey']) ?></span></td>
                                <td style="font-weight: 600;"><?= e($mem['full_name']) ?></td>
                                <td><?= $typeBadge ?></td>
                                <td style="text-align: right;">
                                    <a href="member_list.php" class="btn btn-ghost" style="padding: 4px 10px; font-size:.7rem;">View</a>
                                </td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div style="display:flex; flex-direction:column; gap:24px;">

            <div class="card" style="flex: 1;">
                <div class="card-head">
                    <div>
                        <div class="card-head-title">System Activity</div>
                        <div class="card-head-sub">Recent operators</div>
                    </div>
                    <a href="settings.php" class="card-link">Manage Operators →</a>
                </div>
                <div style="padding: 12px 0;">
                    <?php if (empty($recentUsers)): ?>
                        <div style="text-align:center; padding: 20px; color:var(--text-muted); font-size:.8rem;">No operator activity recorded.</div>
                    <?php else: foreach ($recentUsers as $u):
                        $lastLogin = $u['last_login_at'] ? date('M j, Y h:i A', strtotime($u['last_login_at'])) : 'Never logged in';
                    ?>
                    <div style="padding: 14px 24px; border-bottom: 1px solid var(--border);">
                        <div style="font-size: .85rem; font-weight: 600; color: var(--text-primary);">
                            <?= e($u['full_name'] ?? $u['username']) ?>
                        </div>
                        <div style="font-size: .7rem; color: var(--text-muted); margin-top: 3px;">
                            Last Login: <span class="mono"><?= e($lastLogin) ?></span>
                        </div>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>

        </div>
    </div>

</main>
</div><script>
(function () {
    const canvas = document.getElementById('revenueChart');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');

    // Load data from PHP
    const data   = <?= json_encode($chartData) ?>;
    const labels = <?= json_encode($chartLabels) ?>;

    // HiDPI
    const dpr = window.devicePixelRatio || 1;
    const rect = canvas.parentElement.getBoundingClientRect();
    canvas.width  = rect.width  * dpr;
    canvas.height = rect.height * dpr;
    ctx.scale(dpr, dpr);
    const W = rect.width, H = rect.height;

    const pad = { top: 20, right: 20, bottom: 36, left: 56 };
    const chartW = W - pad.left - pad.right;
    const chartH = H - pad.top  - pad.bottom;

    const max   = Math.max(...data) * 1.1;
    const xStep = chartW / (data.length - 1);

    const xOf = i => pad.left + i * xStep;
    const yOf = v => pad.top + chartH - (v / max) * chartH;

    // Gradient fill
    const grad = ctx.createLinearGradient(0, pad.top, 0, pad.top + chartH);
    grad.addColorStop(0, 'rgba(26,26,46,.15)');
    grad.addColorStop(1, 'rgba(26,26,46,.0)');

    // Grid lines
    ctx.strokeStyle = '#e8eaed';
    ctx.lineWidth = 1;
    for (let i = 0; i <= 4; i++) {
        const y = pad.top + (chartH / 4) * i;
        ctx.beginPath(); ctx.moveTo(pad.left, y); ctx.lineTo(W - pad.right, y);
        ctx.stroke();
        const val = Math.round(max - (max / 4) * i);
        ctx.fillStyle = '#9ca3af';
        ctx.font = '500 11px Sora, sans-serif';
        ctx.textAlign = 'right';
        ctx.fillText(val.toLocaleString(), pad.left - 8, y + 4);
    }

    // Area fill
    ctx.beginPath();
    ctx.moveTo(xOf(0), yOf(data[0]));
    for (let i = 1; i < data.length; i++) {
        const cx = (xOf(i-1) + xOf(i)) / 2;
        ctx.bezierCurveTo(cx, yOf(data[i-1]), cx, yOf(data[i]), xOf(i), yOf(data[i]));
    }
    ctx.lineTo(xOf(data.length - 1), pad.top + chartH);
    ctx.lineTo(xOf(0), pad.top + chartH);
    ctx.closePath();
    ctx.fillStyle = grad;
    ctx.fill();

    // Line
    ctx.beginPath();
    ctx.moveTo(xOf(0), yOf(data[0]));
    for (let i = 1; i < data.length; i++) {
        const cx = (xOf(i-1) + xOf(i)) / 2;
        ctx.bezierCurveTo(cx, yOf(data[i-1]), cx, yOf(data[i]), xOf(i), yOf(data[i]));
    }
    ctx.strokeStyle = '#1a1a2e';
    ctx.lineWidth = 2.5;
    ctx.lineJoin = 'round';
    ctx.stroke();

    // Dots + labels
    data.forEach((v, i) => {
        ctx.beginPath();
        ctx.arc(xOf(i), yOf(v), 4.5, 0, Math.PI * 2);
        ctx.fillStyle = i === data.length - 1 ? '#1a1a2e' : '#fff';
        ctx.strokeStyle = '#1a1a2e';
        ctx.lineWidth = 2;
        ctx.fill(); ctx.stroke();

        ctx.fillStyle = '#9ca3af';
        ctx.font = '600 11px Sora, sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText(labels[i], xOf(i), H - pad.bottom + 18);
    });
})();
</script>

</body>
</html>