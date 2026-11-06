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
 * discounted_members.php
 * UI Page — Discounted Members Management
 * Lists all members with applied discounts; allows applying and removing discounts.
 * Includes AUTO-DISCOUNT awareness banner for Senior Citizens (age ≥ 60).
 */

require_once '../database/config.php';

requireLogin();

$flash = flash();
$pdo   = getDB();

// ── Pagination ────────────────────────────────────────────────────
$limit  = 15;
$page   = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

// ── Filters ───────────────────────────────────────────────────────
$search       = trim($_GET['search']        ?? '');
$filterType   = trim($_GET['discount_type'] ?? '');

// ── Build WHERE ───────────────────────────────────────────────────
$whereClauses = [];
$params       = [];

if ($search !== '') {
    $whereClauses[] = "(m.lastname LIKE :s1 OR m.firstname LIKE :s2 OR CAST(m.pkey AS CHAR) LIKE :s3)";
    $params[':s1']  = "%$search%";
    $params[':s2']  = "%$search%";
    $params[':s3']  = "%$search%";
}
if ($filterType !== '') {
    $whereClauses[] = "d.discount_type = :dtype";
    $params[':dtype'] = $filterType;
}
$whereSql = $whereClauses ? ' WHERE ' . implode(' AND ', $whereClauses) : '';

// ── Total count ───────────────────────────────────────────────────
try {
    $countStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM discounted_members dm
         JOIN water_accounts   m ON m.pkey        = dm.member_id
         JOIN discounts d ON d.discount_id = dm.discount_id"
        . $whereSql
    );
    foreach ($params as $k => $v) { $countStmt->bindValue($k, $v); }
    $countStmt->execute();
    $totalRecords = (int)$countStmt->fetchColumn();
    $totalPages   = max(1, (int)ceil($totalRecords / $limit));
} catch (PDOException $e) {
    $totalRecords = 0;
    $totalPages   = 1;
}

// ── Fetch paginated results ───────────────────────────────────────
try {
    $sql = "SELECT dm.dm_id,
                   dm.member_id,
                   dm.created_at                              AS date_applied,
                   CONCAT_WS(', ', NULLIF(m.lastname, ''), NULLIF(m.firstname, ''))     AS member_name,
                   m.dateofbirth,
                   TIMESTAMPDIFF(YEAR, m.dateofbirth, CURDATE()) AS age,
                   d.discount_type,
                   (
                       CASE WHEN d.water_discount_active = 1 AND d.water_discount_method <> 'none' THEN 1 ELSE 0 END +
                       CASE WHEN d.one_time_discount_active = 1 AND d.one_time_discount_method <> 'none' THEN 1 ELSE 0 END +
                       CASE WHEN d.installment_discount_active = 1 AND d.installment_discount_method <> 'none' THEN 1 ELSE 0 END +
                       CASE WHEN d.recurring_discount_active = 1 AND d.recurring_discount_method <> 'none' THEN 1 ELSE 0 END
                   ) AS active_rule_count
            FROM discounted_members dm
            JOIN water_accounts   m ON m.pkey        = dm.member_id
            JOIN discounts d ON d.discount_id = dm.discount_id"
        . $whereSql
        . " ORDER BY dm.created_at DESC
            LIMIT :lim OFFSET :off";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
    $stmt->bindValue(':lim', $limit,  PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $records = [];
    $flash   = ['type' => 'error', 'msg' => 'Failed to load records: ' . $e->getMessage()];
}

// ── Load all members for the "Apply Discount" dropdown ───────────
try {
    $membersStmt = $pdo->prepare(
        "SELECT pkey,
                CONCAT_WS(', ', NULLIF(lastname, ''), NULLIF(firstname, '')) AS full_name,
                dateofbirth,
                TIMESTAMPDIFF(YEAR, dateofbirth, CURDATE()) AS age
         FROM water_accounts
         WHERE status = 'A'
         ORDER BY lastname ASC, firstname ASC"
    );
    $membersStmt->execute();
    $membersList = $membersStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $membersList = [];
}

// ── Load all discount types for filter & modal ────────────────────
try {
    $discountsStmt = $pdo->prepare(
        "SELECT discount_id, discount_type
         FROM discounts
         ORDER BY discount_type ASC"
    );
    $discountsStmt->execute();
    $discountsList = $discountsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $discountsList = [];
}

// ── Count of eligible-but-unassigned senior members ──────────────
try {
    $seniorCountStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM water_accounts m
         WHERE m.status = 'A'
           AND TIMESTAMPDIFF(YEAR, m.dateofbirth, CURDATE()) >= 60
           AND NOT EXISTS (
               SELECT 1 FROM discounted_members dm
               JOIN discounts d ON d.discount_id = dm.discount_id
               WHERE dm.member_id = m.pkey
                 AND LOWER(d.discount_type) LIKE '%senior%'
           )"
    );
    $seniorCountStmt->execute();
    $unassignedSeniorCount = (int)$seniorCountStmt->fetchColumn();
} catch (PDOException $e) {
    $unassignedSeniorCount = 0;
}

// ── Helpers ───────────────────────────────────────────────────────
function buildUrl(int $pageNum): string {
    $q             = $_GET;
    $q['page']     = $pageNum;
    return '?' . http_build_query($q);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../common/fonts.css">
    <title>Discounted Members</title>
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
            --radius-sm:      8px;
            --radius:         14px;
            --radius-lg:      20px;
            --shadow-sm:      0 1px 3px rgba(0,0,0,.06), 0 1px 2px rgba(0,0,0,.04);
            --shadow:         0 4px 16px rgba(0,0,0,.08), 0 1px 4px rgba(0,0,0,.04);
            --shadow-lg:      0 20px 60px rgba(0,0,0,.14), 0 4px 16px rgba(0,0,0,.08);
        }

        html { height: 100%; font-size: 16px; }
        body {
            font-family: 'Sora', sans-serif;
            background: var(--off-white);
            color: var(--text-primary);
            min-height: 100vh;
        }

        /* ── PAGE LAYOUT ───────────────────────────────────────────────── */
        .page {
            padding: 36px 36px 48px;
            animation: fadeIn .4s ease both;
        }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }

        .page-header {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            margin-bottom: 24px;
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

        /* ── ALERTS ────────────────────────────────────────────────────── */
        .alert {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 14px 18px;
            border-radius: var(--radius-sm);
            font-size: .8375rem;
            font-weight: 500;
            margin-bottom: 24px;
            border: 1px solid transparent;
        }
        .alert-success { background: var(--success-bg); color: var(--success); border-color: #a7f3d0; }
        .alert-error   { background: var(--error-bg);   color: var(--error);   border-color: #fecaca; }
        .alert-warning { background: var(--warning-bg); color: var(--warning); border-color: #fde68a; }
        .alert-info    { background: var(--info-bg);    color: var(--info);    border-color: #bfdbfe; }

        /* ── AUTOMATIC SENIOR CLASSIFICATION NOTE ─────────────────────── */
        .auto-banner {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            background: #ffffff;
            border: 1px solid var(--border);
            border-left: 4px solid #6b7280;
            border-radius: var(--radius-sm);
            padding: 13px 15px;
            margin-bottom: 20px;
        }
        .auto-banner-icon {
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .auto-banner-icon svg { width: 16px; height: 16px; color: #6b7280; }
        .auto-banner-title {
            font-size: .82rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 2px;
        }
        .auto-banner-body {
            font-size: .78rem;
            color: var(--text-secondary);
            line-height: 1.5;
        }
        .auto-banner-highlight {
            font-weight: 700;
            color: var(--text-primary);
        }

        /* ── BUTTONS ───────────────────────────────────────────────────── */
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
            transition: transform .15s, box-shadow .15s, background .15s, color .15s;
            letter-spacing: -.01em;
            white-space: nowrap;
        }
        .btn svg { width: 15px; height: 15px; flex-shrink: 0; }
        .btn-primary {
            background: var(--accent);
            color: var(--white);
            box-shadow: 0 2px 8px rgba(26,26,46,.2);
        }
        .btn-primary:hover { background: #0f0f1a; transform: translateY(-1px); box-shadow: 0 6px 20px rgba(26,26,46,.28); }
        .btn-warning {
            background: var(--warning-bg);
            color: var(--warning);
            border: 1.5px solid #fde68a;
        }
        .btn-warning:hover { background: #fef3c7; transform: translateY(-1px); }
        .btn-danger {
            background: var(--error-bg);
            color: var(--error);
            border: 1.5px solid #fecaca;
        }
        .btn-danger:hover { background: #fee2e2; transform: translateY(-1px); }
        .btn-secondary {
            background: var(--white);
            color: var(--text-secondary);
            border: 1.5px solid var(--border);
        }
        .btn-secondary:hover { border-color: var(--border-focus); color: var(--text-primary); }

        /* ── CARD ──────────────────────────────────────────────────────── */
        .card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            margin-bottom: 24px;
        }
        .card-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 20px 24px;
            border-bottom: 1px solid var(--border);
            flex-wrap: wrap;
            gap: 15px;
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
        .filter-form {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }
        .filter-input {
            padding: 8px 14px;
            border: 1.5px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: .8125rem;
            font-family: inherit;
            outline: none;
            transition: border-color .15s;
            background: var(--white);
            color: var(--text-primary);
        }
        .filter-input:focus { border-color: var(--border-focus); }
        .filter-search { min-width: 220px; }

        /* ── TABLE ─────────────────────────────────────────────────────── */
        .table-wrap { overflow-x: auto; }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: .8125rem;
        }
        thead th {
            padding: 12px 24px;
            text-align: left;
            font-size: .7rem;
            font-weight: 700;
            letter-spacing: .07em;
            text-transform: uppercase;
            color: var(--text-muted);
            background: var(--off-white);
            border-bottom: 1px solid var(--border);
        }
        tbody tr { border-bottom: 1px solid var(--border); transition: background .15s; }
        tbody tr:last-child { border-bottom: none; }
        tbody tr:hover { background: var(--off-white); }
        tbody td { padding: 14px 24px; color: var(--text-primary); vertical-align: middle; }

        .inv-id {
            font-family: 'JetBrains Mono', monospace;
            font-size: .78rem;
            font-weight: 500;
            color: var(--text-secondary);
        }
        .inv-client { font-weight: 600; }
        .cell-sub {
            color: var(--text-secondary);
            font-size: .75rem;
            margin-top: 3px;
        }

        /* ── BADGES ────────────────────────────────────────────────────── */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 9px;
            border-radius: 20px;
            font-size: .7rem;
            font-weight: 600;
            letter-spacing: .02em;
        }
        .badge-dot { width: 6px; height: 6px; border-radius: 50%; }
        .badge-success { background: var(--success-bg); color: var(--success); }
        .badge-success .badge-dot { background: var(--success); }
        .badge-warning { background: var(--warning-bg); color: var(--warning); }
        .badge-warning .badge-dot { background: var(--warning); }
        .badge-info    { background: var(--info-bg);    color: var(--info); }
        .badge-info    .badge-dot { background: var(--info); }
        .badge-senior {
            background: #fef3c7;
            color: #92400e;
        }
        .badge-senior .badge-dot { background: #d97706; }

        /* ── RATE PILL ─────────────────────────────────────────────────── */
        .rate-pill {
            display: inline-block;
            background: var(--accent-light);
            color: var(--accent);
            font-family: 'JetBrains Mono', monospace;
            font-size: .75rem;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 20px;
            margin-left: 6px;
        }

        /* ── ACTION BUTTONS ────────────────────────────────────────────── */
        .action-btn {
            padding: 5px 11px;
            border-radius: 6px;
            border: 1.5px solid var(--border);
            background: none;
            font-family: inherit;
            font-size: .75rem;
            font-weight: 600;
            color: var(--text-secondary);
            cursor: pointer;
            text-decoration: none;
            transition: border-color .15s, color .15s, background .15s;
        }
        .action-btn:hover { border-color: var(--border-focus); color: var(--text-primary); }
        .action-btn-danger { color: var(--error); border-color: #fecaca; }
        .action-btn-danger:hover { background: var(--error-bg); border-color: var(--error); }

        /* ── PAGINATION ────────────────────────────────────────────────── */
        .pagination {
            display: flex;
            list-style: none;
            gap: 5px;
            padding: 16px 24px;
            justify-content: flex-end;
            align-items: center;
            background: var(--off-white);
            border-top: 1px solid var(--border);
        }
        .page-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 30px;
            height: 30px;
            padding: 0 10px;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            text-decoration: none;
            color: var(--text-secondary);
            font-size: .75rem;
            font-weight: 600;
            transition: all .15s;
            background: var(--white);
        }
        .page-link:hover { border-color: var(--text-primary); color: var(--text-primary); }
        .page-link.active { background: var(--accent); border-color: var(--accent); color: var(--white); }
        .page-link.disabled { color: var(--text-muted); pointer-events: none; background: transparent; border-color: transparent; }

        /* ── EMPTY STATE ───────────────────────────────────────────────── */
        .empty-state {
            text-align: center;
            padding: 48px 24px;
            color: var(--text-muted);
        }
        .empty-state svg { width: 40px; height: 40px; margin-bottom: 12px; opacity: .4; }
        .empty-state p { font-size: .875rem; }

        /* ── MODAL ─────────────────────────────────────────────────────── */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 15, 26, .55);
            backdrop-filter: blur(3px);
            z-index: 1060;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }
        .modal-overlay.active { display: flex; }
        .modal {
            background: var(--white);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            width: 100%;
            max-width: 480px;
            animation: modalIn .25s ease both;
            border: 1px solid var(--border);
        }
        @keyframes modalIn { from { opacity: 0; transform: scale(.96) translateY(8px); } to { opacity: 1; transform: scale(1) translateY(0); } }
        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 22px 26px 18px;
            border-bottom: 1px solid var(--border);
        }
        .modal-title { font-size: 1rem; font-weight: 700; letter-spacing: -.015em; }
        .modal-subtitle { font-size: .775rem; color: var(--text-muted); margin-top: 2px; }
        .modal-close {
            width: 30px; height: 30px;
            border-radius: 50%;
            border: none;
            background: var(--off-white);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-secondary);
            transition: background .15s, color .15s;
        }
        .modal-close:hover { background: var(--border); color: var(--text-primary); }
        .modal-close svg { width: 14px; height: 14px; }
        .modal-body { padding: 24px 26px; }
        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            padding: 16px 26px 22px;
            border-top: 1px solid var(--border);
        }

        /* ── FORM ELEMENTS ─────────────────────────────────────────────── */
        .form-group { margin-bottom: 18px; }
        .form-group:last-child { margin-bottom: 0; }
        .form-label {
            display: block;
            font-size: .775rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 6px;
            letter-spacing: -.01em;
        }
        .form-label span { color: var(--error); margin-left: 2px; }
        .form-control, .form-select {
            width: 100%;
            padding: 10px 14px;
            border: 1.5px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: .8375rem;
            font-family: inherit;
            color: var(--text-primary);
            background: var(--white);
            outline: none;
            transition: border-color .15s, box-shadow .15s;
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--border-focus);
            box-shadow: 0 0 0 3px rgba(26,26,46,.08);
        }
        .form-hint { font-size: .725rem; color: var(--text-muted); margin-top: 5px; }

        /* ── AUTO-DETECT NOTICE (inside modal) ─────────────────────────── */
        .auto-notice {
            display: none;
            align-items: flex-start;
            gap: 10px;
            background: var(--warning-bg);
            border: 1.5px solid #fde68a;
            border-radius: var(--radius-sm);
            padding: 12px 14px;
            font-size: .78rem;
            color: #a16207;
            margin-top: 14px;
            line-height: 1.5;
        }
        .auto-notice.visible { display: flex; }
        .auto-notice svg { width: 16px; height: 16px; flex-shrink: 0; margin-top: 1px; color: var(--warning); }

        /* ── DELETE CONFIRM ────────────────────────────────────────────── */
        .delete-icon {
            width: 52px; height: 52px;
            border-radius: 50%;
            background: var(--error-bg);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
        }
        .delete-icon svg { width: 24px; height: 24px; color: var(--error); }
        .delete-title { font-size: 1rem; font-weight: 700; text-align: center; margin-bottom: 8px; }
        .delete-msg { font-size: .8375rem; color: var(--text-secondary); text-align: center; line-height: 1.55; }
        .delete-target { font-weight: 700; color: var(--text-primary); }

        @media (max-width: 768px) {
            .page { padding: 24px 20px 48px; }
            .card-head { flex-direction: column; align-items: flex-start; }
            .filter-form { width: 100%; flex-direction: column; align-items: stretch; }
            .filter-search, .filter-input, .btn { width: 100%; }
            .pagination { justify-content: center; flex-wrap: wrap; }
        }
    </style>
</head>
<body>

<?php require_once '../common/navbar.php'; ?>

<div class="main-content">
<main class="page">

    <?php if (!empty($flash)): ?>
    <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>">
        <?= htmlspecialchars($flash['msg']) ?>
    </div>
    <?php endif; ?>

    <!-- Page Header -->
    <div class="page-header">
        <div>
            <div class="page-title">Discounted Members</div>
            <div class="page-subtitle">Assign discount classifications to members. The actual amount, percentage, or free cubic-meter benefit is configured in Discount Privilege Rules.</div>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <a class="btn btn-secondary" href="discounts.php">Manage General Discounts</a>
            <button class="btn btn-primary" onclick="openApplyModal()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="12" y1="5" x2="12" y2="19"></line>
                    <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg>
                Apply Discount
            </button>
        </div>
    </div>

    <!-- Auto-Discount Banner -->
    <div class="auto-banner">
        <div class="auto-banner-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"></circle>
                <line x1="12" y1="8" x2="12" y2="12"></line>
                <line x1="12" y1="16" x2="12.01" y2="16"></line>
            </svg>
        </div>
        <div>
            <div class="auto-banner-title">Senior Citizen assignment</div>
            <div class="auto-banner-body">
                Members aged 60 or older are also assigned the Senior Citizen discount.
                <?php if ($unassignedSeniorCount > 0): ?>
                    <span class="auto-banner-highlight"><?= $unassignedSeniorCount ?> eligible member<?= $unassignedSeniorCount !== 1 ? 's' : '' ?></span>
                    still need this assignment.
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Discounted Members Table Card -->
    <div class="card">
        <div class="card-head">
            <div>
                <div class="card-head-title">All Discount Assignments</div>
                <div class="card-head-sub" id="dmCount" data-live-results>
                    Showing <?= number_format(count($records)) ?> of <?= number_format($totalRecords) ?> total assignment<?= $totalRecords !== 1 ? 's' : '' ?>
                </div>
            </div>

            <form method="GET" action="discounted_members.php" class="filter-form">
                <input
                    type="text"
                    name="search"
                    class="filter-input filter-search"
                    placeholder="Search member name..."
                    value="<?= htmlspecialchars($search) ?>"
                    data-live-search
                >
                <select name="discount_type" class="filter-input">
                    <option value="">All Discount Types</option>
                    <?php foreach ($discountsList as $dt): ?>
                    <option value="<?= htmlspecialchars($dt['discount_type']) ?>"
                        <?= $filterType === $dt['discount_type'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($dt['discount_type']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-primary" style="padding: 8px 14px; box-shadow: none;">Filter</button>
                <?php if ($search || $filterType): ?>
                <a href="discounted_members.php" class="btn btn-secondary" style="padding: 8px 14px;">Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <div class="table-wrap" id="dmRecords" data-live-results>
            <table>
                <thead>
                    <tr>
                        <th>Member Name</th>
                        <th>Age</th>
                        <th>Discount Type</th>
                        <th>Privilege Rules</th>
                        <th>Date Applied</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($records)): ?>
                    <tr>
                        <td colspan="6">
                            <div class="empty-state">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                                    <circle cx="9" cy="7" r="4"></circle>
                                    <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                                    <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                                </svg>
                                <p>No discount assignments found<?= ($search || $filterType) ? ' matching your filters' : '' ?>.</p>
                            </div>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($records as $r):
                        $age        = (int)$r['age'];
                        $isSenior   = $age >= 60;
                        $isJunior   = stripos($r['discount_type'], 'student') !== false
                                   || stripos($r['discount_type'], 'youth') !== false;
                    ?>
                    <tr>
                        <td>
                            <span class="inv-client"><?= htmlspecialchars($r['member_name']) ?></span>
                            <?php if ($isSenior): ?>
                                <br><span class="cell-sub" style="color: #d97706;">Senior Citizen</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="inv-id"><?= $age ?></span>
                            <?php if ($isSenior): ?>
                                <span class="badge badge-senior" style="margin-left: 4px;">
                                    <span class="badge-dot"></span>
                                    60+
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= htmlspecialchars($r['discount_type']) ?>
                        </td>
                        <td>
                            <span class="rate-pill">
                                <?= (int)$r['active_rule_count'] ?> active rule<?= (int)$r['active_rule_count'] === 1 ? '' : 's' ?>
                            </span>
                        </td>
                        <td>
                            <div><?= date('M d, Y', strtotime($r['date_applied'])) ?></div>
                            <div class="cell-sub"><?= date('h:i A', strtotime($r['date_applied'])) ?></div>
                        </td>
                        <td>
                            <button class="action-btn action-btn-danger"
                                onclick="openRemoveModal(
                                    <?= (int)$r['dm_id'] ?>,
                                    <?= htmlspecialchars(json_encode($r['member_name'])) ?>,
                                    <?= htmlspecialchars(json_encode($r['discount_type'])) ?>
                                )">
                                Remove
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div id="dmPages" data-live-results>
        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <a href="<?= $page > 1 ? buildUrl($page - 1) : '#' ?>"
               class="page-link <?= $page <= 1 ? 'disabled' : '' ?>">Prev</a>

            <?php
            $start = max(1, $page - 2);
            $end   = min($totalPages, $page + 2);
            if ($start > 1) {
                echo '<a href="' . buildUrl(1) . '" class="page-link">1</a>';
                if ($start > 2) echo '<span class="page-link disabled">…</span>';
            }
            for ($i = $start; $i <= $end; $i++):
            ?>
            <a href="<?= buildUrl($i) ?>" class="page-link <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor;
            if ($end < $totalPages) {
                if ($end < $totalPages - 1) echo '<span class="page-link disabled">…</span>';
                echo '<a href="' . buildUrl($totalPages) . '" class="page-link">' . $totalPages . '</a>';
            }
            ?>

            <a href="<?= $page < $totalPages ? buildUrl($page + 1) : '#' ?>"
               class="page-link <?= $page >= $totalPages ? 'disabled' : '' ?>">Next</a>
        </div>
        <?php endif; ?>
        </div>
    </div>

</main>
</div>

<!-- ================================================================
     MODAL: APPLY DISCOUNT
================================================================ -->
<div class="modal-overlay" id="applyModal">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="applyModalTitle">
        <div class="modal-header">
            <div>
                <div class="modal-title" id="applyModalTitle">Apply Discount to Member</div>
                <div class="modal-subtitle">Select a member and assign a discount type.</div>
            </div>
            <button class="modal-close" onclick="closeApplyModal()" aria-label="Close">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line>
                </svg>
            </button>
        </div>
        <form method="POST" action="../process/addDiscountedMembers.php" id="applyForm"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <div class="modal-body">

                <!-- Member Select -->
                <div class="form-group">
                    <label class="form-label" for="apply_member">
                        Member <span>*</span>
                    </label>
                    <select id="apply_member" name="member_id" class="form-select" required onchange="checkSenior(this)">
                        <option value="">— Select a member —</option>
                        <?php foreach ($membersList as $mem):
                            $age = (int)$mem['age'];
                        ?>
                        <option value="<?= (int)$mem['pkey'] ?>"
                                data-age="<?= $age ?>"
                                data-name="<?= htmlspecialchars($mem['full_name']) ?>">
                            MEM-<?= str_pad($mem['pkey'], 5, '0', STR_PAD_LEFT) ?> — <?= htmlspecialchars($mem['full_name']) ?>
                            <?= $age >= 60 ? ' (Age ' . $age . ' — Senior)' : ' (Age ' . $age . ')' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-hint">Only active members are shown.</div>
                </div>

                <!-- Discount Type Select -->
                <div class="form-group">
                    <label class="form-label" for="apply_discount">
                        Discount Type <span>*</span>
                    </label>
                    <select id="apply_discount" name="discount_id" class="form-select" required>
                        <option value="">— Select a discount type —</option>
                        <?php foreach ($discountsList as $dt): ?>
                        <option value="<?= (int)$dt['discount_id'] ?>"
                                data-type="<?= htmlspecialchars($dt['discount_type']) ?>">
                            <?= htmlspecialchars($dt['discount_type']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Auto-discount notice (shown when senior member is selected) -->
                <div class="auto-notice" id="seniorNotice">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="8" x2="12" y2="12"></line>
                        <line x1="12" y1="16" x2="12.01" y2="16"></line>
                    </svg>
                    <span id="seniorNoticeText">
                        This member is a Senior Citizen (60+). The system will preserve your selected classification and also ensure that the <strong>Senior Citizen</strong> classification is assigned.
                    </span>
                </div>

            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeApplyModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="20 6 9 17 4 12"></polyline>
                    </svg>
                    Apply Discount
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ================================================================
     MODAL: REMOVE CONFIRM
================================================================ -->
<div class="modal-overlay" id="removeModal">
    <div class="modal" style="max-width: 400px;" role="dialog" aria-modal="true" aria-labelledby="removeModalTitle">
        <form method="POST" action="../process/deleteDiscountedMembers.php" id="removeForm"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="dm_id" id="remove_dm_id">
            <div class="modal-body" style="padding-top: 30px; padding-bottom: 10px;">
                <div class="delete-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                        <circle cx="9" cy="7" r="4"></circle>
                        <line x1="17" y1="11" x2="23" y2="11"></line>
                    </svg>
                </div>
                <div class="delete-title" id="removeModalTitle">Remove Discount</div>
                <div class="delete-msg">
                    Remove the <span class="delete-target" id="remove_discount_label"></span> discount
                    from <span class="delete-target" id="remove_member_label"></span>?
                    This action cannot be undone.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeRemoveModal()">Cancel</button>
                <button type="submit" class="btn btn-danger">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                    Yes, Remove
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    // ── APPLY MODAL ────────────────────────────────────────────────
    function openApplyModal() {
        document.getElementById('applyForm').reset();
        document.getElementById('seniorNotice').classList.remove('visible');
        document.getElementById('applyModal').classList.add('active');
        document.getElementById('apply_member').focus();
    }
    function closeApplyModal() {
        document.getElementById('applyModal').classList.remove('active');
    }

    /**
     * When a member is selected in the Apply modal, check if they are
     * 60+ years old and show the auto-discount notice accordingly.
     */
    function checkSenior(selectEl) {
        const selected = selectEl.options[selectEl.selectedIndex];
        const age      = parseInt(selected.getAttribute('data-age'), 10);
        const notice   = document.getElementById('seniorNotice');
        const noteText = document.getElementById('seniorNoticeText');

        if (!isNaN(age) && age >= 60) {
            noteText.innerHTML = 'This member is a <strong>Senior Citizen (Age ' + age + ')</strong>. '
                + 'The system will automatically override your selection and assign the '
                + '<strong>Senior Citizen</strong> discount.';
            notice.classList.add('visible');
        } else {
            notice.classList.remove('visible');
        }
    }

    // ── REMOVE MODAL ───────────────────────────────────────────────
    function openRemoveModal(dmId, memberName, discountType) {
        document.getElementById('remove_dm_id').value              = dmId;
        document.getElementById('remove_member_label').textContent  = memberName;
        document.getElementById('remove_discount_label').textContent = '"' + discountType + '"';
        document.getElementById('removeModal').classList.add('active');
    }
    function closeRemoveModal() {
        document.getElementById('removeModal').classList.remove('active');
    }

    // ── CLOSE ON OVERLAY CLICK ─────────────────────────────────────
    document.querySelectorAll('.modal-overlay').forEach(function (overlay) {
        overlay.addEventListener('click', function (e) {
            if (e.target === this) this.classList.remove('active');
        });
    });

    // ── CLOSE ON ESC ──────────────────────────────────────────────
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-overlay.active')
                    .forEach(function (m) { m.classList.remove('active'); });
        }
    });
</script>

</body>
</html>