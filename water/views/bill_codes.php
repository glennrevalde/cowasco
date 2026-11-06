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
    function e($value) { return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8'); }
}

$current_page = basename($_SERVER['PHP_SELF'], '.php');

// ==========================================
// DYNAMIC DATA FETCHING
// ==========================================
$gl_accounts = [];
$bill_codes   = [];
$bill_code_edit_data = [];
$inventory_items = [];   // active inventory items of the cooperative Item List (an Inventory bill code sells one)

if (isset($pdo)) {
    try {
        $item_stmt = $pdo->query("
            SELECT i.id, i.code, i.name, i.unit, i.avg_cost,
                   COALESCE((SELECT SUM(s.qty_on_hand) FROM item_site_stock s WHERE s.item_id = i.id), 0) AS on_hand
            FROM items i
            WHERE i.item_type = 'inventory' AND i.is_active = 1
            ORDER BY i.code ASC, i.name ASC
        ");
        $inventory_items = $item_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Item List Fetch Error: " . $e->getMessage());
    }

    try {
        // Show normal posting/detail accounts, plus any GL account that is already
        // assigned to a bill code. This guarantees the Edit modal can preselect
        // the bill code's current Debit/Credit mapping even when an existing
        // mapping falls outside the normal account-list filter.
        // the one chart of accounts (accounts): active accounts, plus any account a bill code already uses
        $gl_stmt = $pdo->query("
            SELECT DISTINCT a.id AS AccntID, CONCAT(a.code, ' ', a.name) AS AccntName, a.code AS AccntCode, a.is_active
            FROM accounts a
            LEFT JOIN bill_codes used_bc
              ON a.id = used_bc.ar_account
              OR a.id = used_bc.income_account
              OR a.id = used_bc.cogs_account
              OR a.id = used_bc.asset_account
            WHERE a.is_active = 1
               OR used_bc.code_id IS NOT NULL
            ORDER BY a.code ASC, a.name ASC
        ");
        $gl_accounts = $gl_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("GL Fetch Error: " . $e->getMessage());
    }

    try {
        $bc_stmt = $pdo->query("
            SELECT bc.*,
                   ar.AccntName   AS ar_name,
                   inc.AccntName  AS inc_name,
                   cogs.AccntName AS cogs_name,
                   ast.AccntName  AS ast_name,
                   it.code        AS item_code,
                   it.name        AS item_name,
                   it.is_active   AS item_is_active,
                   it.avg_cost    AS item_avg_cost,
                   COALESCE((SELECT SUM(s.qty_on_hand) FROM item_site_stock s WHERE s.item_id = it.id), 0) AS item_on_hand
            FROM bill_codes bc
            LEFT JOIN chartofaccntstbl ar   ON bc.ar_account     = ar.AccntID
            LEFT JOIN chartofaccntstbl inc  ON bc.income_account  = inc.AccntID
            LEFT JOIN chartofaccntstbl cogs ON bc.cogs_account    = cogs.AccntID
            LEFT JOIN chartofaccntstbl ast  ON bc.asset_account   = ast.AccntID
            LEFT JOIN items it              ON bc.item_id         = it.id
            ORDER BY bc.created_at DESC
        ");
        $bill_codes = $bc_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Keep one authoritative, JSON-safe copy of each editable record.
        // The Edit buttons pass only the code ID; the modal reads all saved
        // values from this map instead of rebuilding them in an inline
        // JavaScript argument list.
        foreach ($bill_codes as $billCode) {
            $codeId = (string)($billCode['code_id'] ?? '');
            if ($codeId === '') continue;

            $bill_code_edit_data[$codeId] = [
                'code_id' => (int)$billCode['code_id'],
                'code' => (string)($billCode['code'] ?? ''),
                'description' => (string)($billCode['description'] ?? ''),
                'type' => (string)($billCode['type'] ?? ''),
                'ar_account' => $billCode['ar_account'] !== null ? (string)$billCode['ar_account'] : '',
                'ar_name' => (string)($billCode['ar_name'] ?? ''),
                'income_account' => $billCode['income_account'] !== null ? (string)$billCode['income_account'] : '',
                'income_name' => (string)($billCode['inc_name'] ?? ''),
                'cogs_account' => $billCode['cogs_account'] !== null ? (string)$billCode['cogs_account'] : '',
                'cogs_name' => (string)($billCode['cogs_name'] ?? ''),
                'asset_account' => $billCode['asset_account'] !== null ? (string)$billCode['asset_account'] : '',
                'asset_name' => (string)($billCode['ast_name'] ?? ''),
                'item_id' => !empty($billCode['item_id']) ? (string)$billCode['item_id'] : '',
                'item_label' => !empty($billCode['item_id']) ? trim((string)($billCode['item_code'] ?? '') . ' - ' . (string)($billCode['item_name'] ?? '')) : '',
                'sales_price' => (string)($billCode['sales_price'] ?? '0.00'),
                'cost_price' => (string)($billCode['cost_price'] ?? '0.00'),
                'allow_member_discount' => (int)($billCode['allow_member_discount'] ?? 0),
            ];
        }
    } catch (PDOException $e) {
        error_log("Bill Codes Fetch Error: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../common/fonts.css">
    <title>Bill Codes Dictionary - <?= htmlspecialchars(app_name(), ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        /* ── Reset & Root ─────────────────────────────────────── */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --white:         #ffffff;
            --off-white:     #f8f9fb;
            --border:        #e8eaed;
            --border-focus:  #1a1a2e;
            --text-primary:  #0f0f1a;
            --text-secondary:#6b7280;
            --text-muted:    #9ca3af;
            --accent:        #1a1a2e;
            --success:       #059669;
            --success-bg:    #ecfdf5;
            --danger:        #dc2626;
            --danger-bg:     #fef2f2;
            --warning:       #d97706;
            --radius-sm:     8px;
            --radius:        14px;
            --radius-lg:     20px;
            --shadow-sm:     0 1px 3px rgba(0,0,0,.06), 0 1px 2px rgba(0,0,0,.04);
            --shadow-lg:     0 20px 60px rgba(0,0,0,.18);
            --transition:    0.2s cubic-bezier(0.4,0,0.2,1);
        }

        body {
            font-family: 'Sora', sans-serif;
            background: var(--off-white);
            color: var(--text-primary);
            -webkit-text-size-adjust: 100%;
        }

        /* ── Layout ───────────────────────────────────────────── */
        .page {
            padding: 24px 16px 60px;
            animation: fadeIn .4s ease both;
            max-width: 1400px;
            margin: 0 auto;
            width: 100%;
        }

        @media (min-width: 640px)  { .page { padding: 28px 24px 60px; } }
        @media (min-width: 1024px) { .page { padding: 32px 32px 60px; } }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(12px); }
            to   { opacity: 1; transform: translateY(0);    }
        }

        /* ── Page Header ──────────────────────────────────────── */
        .header { display: flex; flex-direction: column; align-items: flex-start; margin-bottom: 20px; gap: 10px; }
        .back-link {
            display: inline-flex; align-items: center; gap: 6px;
            color: var(--text-secondary); text-decoration: none;
            font-size: .8rem; font-weight: 600; margin-bottom: 2px;
        }
        .back-link:hover { color: var(--text-primary); }
        .back-link svg { flex-shrink: 0; }
        .title    { font-size: clamp(1.2rem, 4vw, 1.5rem); font-weight: 700; }
        .subtitle { font-size: .875rem; color: var(--text-secondary); margin-top: 4px; }

        /* ── Alerts ───────────────────────────────────────────── */
        .alert {
            padding: 12px 16px; border-radius: var(--radius-sm);
            margin-bottom: 20px; font-size: .875rem; font-weight: 600;
            display: flex; align-items: center; gap: 8px;
        }
        .alert-success { background: var(--success-bg); border: 1px solid var(--success); color: var(--success); }
        .alert-danger  { background: var(--danger-bg);  border: 1px solid var(--danger);  color: var(--danger);  }

        /* ── Card ─────────────────────────────────────────────── */
        .card {
            background: var(--white); border: 1px solid var(--border);
            border-radius: var(--radius); padding: 20px;
            margin-bottom: 24px; box-shadow: var(--shadow-sm);
        }
        @media (min-width: 640px) { .card { padding: 24px; } }

        .card-header {
            display: flex; justify-content: space-between; align-items: center;
            flex-wrap: wrap; gap: 12px;
            margin-bottom: 20px; padding-bottom: 12px;
            border-bottom: 1px solid var(--border);
        }
        .card-title { font-size: 1.05rem; font-weight: 600; }

        /* ── Form Elements ────────────────────────────────────── */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 14px;
            margin-bottom: 18px;
            align-items: start;
        }
        @media (min-width: 480px) { .form-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (min-width: 768px) { .form-grid { grid-template-columns: repeat(3, 1fr); } }

        /* colspan helpers */
        .col-span-2 { grid-column: span 2; }
        .col-span-3 { grid-column: 1 / -1; }
        @media (max-width: 479px) { .col-span-2 { grid-column: 1 / -1; } }

        .form-group { display: flex; flex-direction: column; gap: 6px; }

        label {
            font-size: .72rem; font-weight: 700; color: var(--text-secondary);
            text-transform: uppercase; letter-spacing: .05em;
        }

        .form-input {
            padding: 9px 13px; font-family: 'Sora', sans-serif; font-size: .875rem;
            border: 1.5px solid var(--border); border-radius: var(--radius-sm);
            outline: none; background: var(--white); color: var(--text-primary);
            transition: border-color var(--transition), box-shadow var(--transition);
            width: 100%;
        }
        .form-input:focus {
            border-color: var(--border-focus);
            box-shadow: 0 0 0 3px rgba(26,26,46,0.1);
        }
        select.form-input {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%239ca3af' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
            background-repeat: no-repeat; background-position: right 12px center;
            padding-right: 36px; appearance: none; cursor: pointer;
        }

        /* ── Buttons ──────────────────────────────────────────── */
        .btn-group { display: flex; gap: 10px; margin-top: 10px; flex-wrap: wrap; }

        .btn {
            display: inline-flex; align-items: center; justify-content: center;
            gap: 6px; padding: 10px 18px; border: none;
            border-radius: var(--radius-sm); font-family: inherit;
            font-size: .8125rem; font-weight: 600; cursor: pointer;
            text-decoration: none;
            transition: transform .1s, background .2s, border-color .2s, box-shadow .2s;
            white-space: nowrap;
        }
        .btn:active { transform: scale(0.98); }
        .btn-primary   { background: var(--accent); color: var(--white); }
        .btn-primary:hover { background: #0f0f1a; box-shadow: 0 4px 12px rgba(26,26,46,.2); }
        .btn-secondary { background: var(--white); color: var(--text-primary); border: 1.5px solid var(--border); }
        .btn-secondary:hover { border-color: var(--border-focus); background: var(--off-white); }

        .icon-btn {
            width: 32px; height: 32px; border-radius: 8px; border: 1.5px solid var(--border);
            background: var(--white); display: flex; align-items: center; justify-content: center;
            cursor: pointer; color: var(--text-secondary);
            transition: all .2s; text-decoration: none; flex-shrink: 0;
        }
        .icon-btn:hover {
            border-color: var(--border-focus); color: var(--text-primary);
            background: var(--off-white); transform: translateY(-1px);
        }
        .icon-btn svg { width: 14px; height: 14px; }

        /* ── Account Mapping Box ──────────────────────────────── */
        .accnt-box {
            background: var(--off-white); border: 1px dashed var(--border);
            border-radius: var(--radius-sm); padding: 14px; margin-top: 10px;
        }
        .accnt-box-title {
            font-size: .78rem; font-weight: 700; color: var(--text-secondary);
            margin-bottom: 12px; display: flex; align-items: center; gap: 8px;
        }
        .accnt-note { font-size: .72rem; color: var(--text-muted); margin: -4px 0 12px; line-height: 1.5; }
        .field-note { font-size: .7rem; color: var(--text-muted); line-height: 1.45; text-transform: none; letter-spacing: 0; font-weight: 500; }
        .item-link  { font-size: .72rem; color: var(--text-secondary); }
        .item-link strong { color: var(--text-primary); }
        .item-link.missing { color: var(--danger); font-weight: 600; }

        /* ── Discount Option ──────────────────────────────────── */
        .discount-option {
            display: flex; align-items: flex-start; gap: 10px;
            background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af;
            padding: 12px 14px; border-radius: 10px; font-size: .82rem;
            line-height: 1.5; font-weight: 600; cursor: pointer; margin-top: 14px;
        }
        .discount-option input { margin-top: 3px; flex-shrink: 0; cursor: pointer; }

        /* ── Badges ───────────────────────────────────────────── */
        .badge {
            display: inline-flex; align-items: center;
            padding: 3px 9px; border-radius: 20px;
            font-size: .68rem; font-weight: 700; letter-spacing: .02em;
            white-space: nowrap;
        }
        .badge-type         { background: #f3f4f6; color: #4b5563;  border: 1px solid var(--border); }
        .badge-dr           { background: #e0f2fe; color: #0369a1;  border: 1px solid #bae6fd; padding: 2px 6px; font-size: .6rem; }
        .badge-cr           { background: #fce7f3; color: #be185d;  border: 1px solid #fbcfe8; padding: 2px 6px; font-size: .6rem; }
        .badge-discount-on  { background: #ecfdf5; color: #047857;  border: 1px solid #a7f3d0; }
        .badge-discount-off { background: #f3f4f6; color: #6b7280;  border: 1px solid #e5e7eb; }
        .badge-na           { background: #f9fafb; color: #9ca3af;  border: 1px solid #e5e7eb; opacity: .6; }

        /* ── Table (Desktop) ──────────────────────────────────── */
        .card-table-wrap {
            border: 1px solid var(--border);
            border-radius: 0 0 var(--radius) var(--radius);
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        table { width: 100%; border-collapse: collapse; font-size: .85rem; }
        th {
            text-align: left; padding: 11px 14px;
            color: var(--text-secondary); border-bottom: 1.5px solid var(--border);
            font-weight: 600; font-size: .7rem; text-transform: uppercase;
            letter-spacing: .04em; white-space: nowrap;
        }
        td { padding: 13px 14px; border-bottom: 1px solid var(--border); vertical-align: middle; }
        tr:last-child td { border-bottom: none; }
        tbody tr:hover { background: var(--off-white); }

        .mono      { font-family: 'JetBrains Mono', monospace; font-size: .78rem; }
        .mono-bold { font-family: 'JetBrains Mono', monospace; font-size: .83rem; font-weight: 700; }

        /* ── Mobile Card List (hidden on desktop) ─────────────── */
        .mobile-list { display: none; flex-direction: column; gap: 12px; padding: 16px; }

        .bc-card {
            background: var(--white); border: 1px solid var(--border);
            border-radius: var(--radius-sm); padding: 14px;
            box-shadow: var(--shadow-sm);
        }
        .bc-card-top {
            display: flex; justify-content: space-between;
            align-items: flex-start; gap: 8px; margin-bottom: 10px;
        }
        .bc-card-title { font-weight: 700; font-size: .95rem; }
        .bc-card-desc  { font-size: .75rem; color: var(--text-secondary); margin-top: 2px; }
        .bc-card-actions { display: flex; gap: 8px; flex-shrink: 0; }

        .bc-card-body {
            display: grid; grid-template-columns: 1fr 1fr;
            gap: 8px; font-size: .78rem;
        }
        .bc-card-field label {
            font-size: .62rem; letter-spacing: .04em;
            color: var(--text-muted); margin-bottom: 2px; display: block;
        }

        .bc-gl-list { display: flex; flex-direction: column; gap: 4px; margin-top: 8px; padding-top: 8px; border-top: 1px solid var(--border); }
        .bc-gl-row  { font-size: .68rem; display: flex; align-items: center; gap: 6px; color: var(--text-secondary); font-family: 'JetBrains Mono', monospace; }

        /* ── Responsive Breakpoints ───────────────────────────── */
        @media (max-width: 767px) {
            .desktop-table { display: none !important; }
            .mobile-list   { display: flex !important; }
        }

        /* Search bar sizing */
        .search-input {
            padding: 7px 12px; width: 100%; max-width: 280px;
        }
        @media (max-width: 480px) { .search-input { max-width: 100%; } }

        /* ── Modal ────────────────────────────────────────────── */
        .modal-overlay {
            position: fixed; inset: 0;
            background: rgba(10,10,20,0.55); z-index: 1060;
            display: none; align-items: center; justify-content: center;
            opacity: 0; transition: opacity .3s ease;
            backdrop-filter: blur(4px); padding: 12px;
        }
        .modal-overlay.active { display: flex; opacity: 1; }

        .modal-box {
            background: var(--white); border-radius: var(--radius-lg);
            width: 100%; max-width: 860px;
            box-shadow: var(--shadow-lg);
            transform: translateY(24px) scale(0.97); opacity: 0;
            transition: transform 0.35s, opacity 0.35s;
            display: flex; flex-direction: column;
            max-height: 92vh; overflow-y: auto;
        }
        .modal-overlay.active .modal-box { transform: translateY(0) scale(1); opacity: 1; }

        .modal-header {
            padding: 18px 20px; border-bottom: 1px solid var(--border);
            display: flex; justify-content: space-between; align-items: center;
            position: sticky; top: 0; background: var(--white); z-index: 1;
            border-radius: var(--radius-lg) var(--radius-lg) 0 0;
        }
        .modal-body { padding: 20px; }
        .modal-close-btn {
            background: none; border: none; font-size: 1.5rem;
            cursor: pointer; color: var(--text-muted); line-height: 1;
            padding: 4px 8px; border-radius: 6px;
        }
        .modal-close-btn:hover { color: var(--text-primary); background: var(--off-white); }

        /* Footer actions pinned to bottom of modal */
        .modal-footer {
            display: flex; justify-content: flex-end; gap: 10px; flex-wrap: wrap;
            padding: 16px 20px; border-top: 1px solid var(--border);
            position: sticky; bottom: 0; background: var(--white); z-index: 1;
            border-radius: 0 0 var(--radius-lg) var(--radius-lg);
        }
    </style>
</head>
<body>

    <?php require_once '../common/navbar.php'; ?>

    <div class="main-content">
        <main class="page">

            <!-- ── Page Header ────────────────────────────────── -->
            <div class="header">
                <a href="billing_management.php" class="back-link">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="14" height="14">
                        <line x1="19" y1="12" x2="5" y2="12"/>
                        <polyline points="12 19 5 12 12 5"/>
                    </svg>
                    Back to Billing Management
                </a>
                <div>
                    <h1 class="title">Bill Codes Dictionary</h1>
                    <p class="subtitle">Define standard billing categories and establish their automatic General Ledger mapping rules.</p>
                </div>
            </div>

            <!-- ── Flash Messages ──────────────────────────────── -->
            <?php if (isset($_SESSION['success_msg'])): ?>
                <div class="alert alert-success">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                    <?= e($_SESSION['success_msg']); unset($_SESSION['success_msg']); ?>
                </div>
            <?php endif; ?>
            <?php if (isset($_SESSION['error_msg'])): ?>
                <div class="alert alert-danger">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    <?= e($_SESSION['error_msg']); unset($_SESSION['error_msg']); ?>
                </div>
            <?php endif; ?>

            <!-- ── Register New Bill Code ──────────────────────── -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Register New Bill Code</h2>
                </div>

                <form action="../process/addBillCode.php" method="POST" id="billCodeForm"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                    <div class="form-grid">
                        <div class="form-group col-span-2">
                            <label>Code Name (e.g. WATER METER)</label>
                            <input type="text" name="code" id="add_code" class="form-input" style="text-transform:uppercase;" required>
                        </div>
                        <div class="form-group col-span-2">
                            <label>Long Description</label>
                            <input type="text" name="description" id="add_description" class="form-input" placeholder="Auto-filled from Code Name...">
                        </div>
                        <div class="form-group">
                            <label>Bill Type</label>
                            <select name="type" id="add_type" class="form-input" required onchange="toggleAccountFields('add')">
                                <option value="" disabled selected>Select Type...</option>
                                <option value="Service">Service</option>
                                <option value="Inventory">Inventory</option>
                                <option value="Charges">Charges</option>
                            </select>
                        </div>
                        <div class="form-group col-span-3 add-inv-only" style="display:none;">
                            <label>Item (Item List)</label>
                            <select name="item_id" id="add_item_id" class="form-input">
                                <option value="">Select the item this code sells...</option>
                                <?php foreach ($inventory_items as $it): ?>
                                    <option value="<?= e($it['id']) ?>"><?= e($it['code']) ?> - <?= e($it['name']) ?> (on hand <?= number_format((float)$it['on_hand'], 2) ?><?= $it['unit'] !== null && $it['unit'] !== '' ? ' ' . e($it['unit']) : '' ?>, avg cost <?= number_format((float)$it['avg_cost'], 2) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            <span class="field-note">Required for an Inventory bill code. The stock on hand, the average cost and the inventory accounts come from this item of the cooperative Item List (Inventory &gt; Item List).<?= !$inventory_items ? ' No active inventory item exists yet; create one on the Item List first.' : '' ?></span>
                        </div>
                    </div>

                    <div class="accnt-box">
                        <div class="accnt-box-title">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
                            Automated Journal Entry Mapping
                        </div>
                        <div class="form-grid" style="margin-bottom:0;">
                            <div class="form-group">
                                <label>A/R Account <span class="badge badge-dr">DEBIT</span></label>
                                <select name="ar_account" class="form-input" required>
                                    <option value="" disabled selected>Select Account...</option>
                                    <?php foreach ($gl_accounts as $gl): ?>
                                        <option value="<?= e($gl['AccntID']) ?>"><?= e($gl['AccntID']) ?> — <?= e($gl['AccntName']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Income Account <span class="badge badge-cr">CREDIT</span></label>
                                <select name="income_account" class="form-input" required>
                                    <option value="" disabled selected>Select Account...</option>
                                    <?php foreach ($gl_accounts as $gl): ?>
                                        <option value="<?= e($gl['AccntID']) ?>"><?= e($gl['AccntID']) ?> — <?= e($gl['AccntName']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group add-inv-only" style="display:none;">
                                <label>COGS Account <span class="badge badge-dr">DEBIT</span></label>
                                <span class="field-note">Informational: the item's own cost of goods sold account posts.</span>
                                <select name="cogs_account" class="form-input">
                                    <option value="">Select Account...</option>
                                    <?php foreach ($gl_accounts as $gl): ?>
                                        <option value="<?= e($gl['AccntID']) ?>"><?= e($gl['AccntID']) ?> — <?= e($gl['AccntName']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group add-inv-only" style="display:none;">
                                <label>Asset Account <span class="badge badge-cr">CREDIT</span></label>
                                <span class="field-note">Informational: the item's own inventory asset account posts.</span>
                                <select name="asset_account" class="form-input">
                                    <option value="">Select Account...</option>
                                    <?php foreach ($gl_accounts as $gl): ?>
                                        <option value="<?= e($gl['AccntID']) ?>"><?= e($gl['AccntID']) ?> — <?= e($gl['AccntName']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="form-grid" style="margin-top:16px;">
                        <div class="form-group">
                            <label>Sales Price (₱)</label>
                            <input type="number" name="sales_price" class="form-input mono" placeholder="0.00" step="0.01" required>
                        </div>
                        <div class="form-group add-inv-only" style="display:none;">
                            <label>Cost Price (₱)</label>
                            <input type="number" name="cost_price" class="form-input mono" placeholder="0.00" step="0.01">
                            <span class="field-note">Informational: bills are costed at the item's average cost.</span>
                        </div>
                    </div>

                    <label class="discount-option">
                        <input type="checkbox" name="allow_member_discount" value="1">
                        <span>
                            <strong>Allow Member Discount</strong><br>
                            Enable this only if Senior Citizen / PWD / other privileges may apply to this bill code.
                            Inventory bill codes are automatically excluded.
                        </span>
                    </label>

                    <div class="btn-group">
                        <button type="submit" class="btn btn-primary">Save Code</button>
                        <button type="button" class="btn btn-secondary" onclick="clearBillCodeForm()">Clear</button>
                    </div>
                </form>
            </div>

            <!-- ── Active Bill Codes ───────────────────────────── -->
            <div class="card" style="padding:0;">
                <div class="card-header" style="margin:20px 20px 0; padding-bottom:14px;">
                    <h2 class="card-title">Active Bill Codes</h2>
                    <input type="text" id="searchInput" class="form-input search-input" placeholder="Search..." oninput="filterTable()">
                </div>

                <!-- ── Desktop Table ── -->
                <div class="card-table-wrap desktop-table" style="border:none; border-radius:0 0 var(--radius) var(--radius);">
                    <table id="billCodesTable">
                        <thead>
                            <tr>
                                <th>Description / Code</th>
                                <th>Type</th>
                                <th>GL Mapping Rules</th>
                                <th>Pricing</th>
                                <th>Discount</th>
                                <th style="text-align:right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($bill_codes)): ?>
                                <tr>
                                    <td colspan="6" style="text-align:center; padding:32px; color:var(--text-muted);">
                                        No bill codes found. Add one above.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($bill_codes as $code): ?>
                                    <tr class="bc-row" data-search="<?= e(strtolower(($code['code'] ?? '') . ' ' . ($code['description'] ?? '') . ' ' . ($code['type'] ?? '') . ' ' . ($code['item_code'] ?? '') . ' ' . ($code['item_name'] ?? ''))) ?>">
                                        <td>
                                            <div style="font-weight:700;"><?= e($code['code']) ?></div>
                                            <div style="font-size:.75rem; color:var(--text-secondary); margin-top:2px;"><?= e($code['description']) ?></div>
                                        </td>
                                        <td>
                                            <span class="badge badge-type"><?= e($code['type']) ?></span>
                                        </td>
                                        <td>
                                            <div style="display:grid; gap:4px;">
                                                <div class="mono" style="font-size:.68rem; color:var(--text-secondary); display:flex; align-items:center; gap:4px;">
                                                    <span class="badge badge-dr">DR</span>
                                                    <?= e($code['ar_account'] ?? 'None') ?> — <?= e($code['ar_name'] ?? 'N/A') ?>
                                                </div>
                                                <div class="mono" style="font-size:.68rem; color:var(--text-secondary); display:flex; align-items:center; gap:4px;">
                                                    <span class="badge badge-cr">CR</span>
                                                    <?= e($code['income_account'] ?? 'None') ?> — <?= e($code['inc_name'] ?? 'N/A') ?>
                                                </div>
                                                <?php if ($code['type'] === 'Inventory'): ?>
                                                    <div class="mono" style="font-size:.68rem; color:var(--text-secondary); display:flex; align-items:center; gap:4px;" title="Informational: the item's own COGS account posts">
                                                        <span class="badge badge-dr">DR</span>
                                                        <?= e($code['cogs_account'] ?? 'None') ?> — <?= e($code['cogs_name'] ?? 'N/A') ?> (info)
                                                    </div>
                                                    <div class="mono" style="font-size:.68rem; color:var(--text-secondary); display:flex; align-items:center; gap:4px;" title="Informational: the item's own inventory asset account posts">
                                                        <span class="badge badge-cr">CR</span>
                                                        <?= e($code['asset_account'] ?? 'None') ?> — <?= e($code['ast_name'] ?? 'N/A') ?> (info)
                                                    </div>
                                                    <?php if (!empty($code['item_id']) && !empty($code['item_code'])): ?>
                                                        <div class="item-link">Item: <strong><?= e($code['item_code']) ?></strong> <?= e($code['item_name']) ?>, on hand <?= number_format((float)$code['item_on_hand'], 2) ?>, avg cost <?= number_format((float)$code['item_avg_cost'], 2) ?><?= (int)($code['item_is_active'] ?? 1) !== 1 ? ' (inactive item)' : '' ?></div>
                                                    <?php else: ?>
                                                        <div class="item-link missing">Not linked to an item of the Item List</div>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="mono-bold" style="color:var(--success);">₱ <?= number_format((float)($code['sales_price'] ?? 0), 2) ?></div>
                                            <?php if ($code['type'] === 'Inventory'): ?>
                                                <div class="mono" style="font-size:.72rem; color:var(--text-secondary); margin-top:3px;">
                                                    Cost: ₱ <?= number_format((float)($code['cost_price'] ?? 0), 2) ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>

                                        <!-- ★ FIX: Dedicated Discount <td> -->
                                        <td>
                                            <?php if ($code['type'] === 'Inventory'): ?>
                                                <span class="badge badge-na">N/A</span>
                                            <?php elseif (!empty($code['allow_member_discount'])): ?>
                                                <span class="badge badge-discount-on">✓ Allowed</span>
                                            <?php else: ?>
                                                <span class="badge badge-discount-off">— Off</span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- ★ FIX: Dedicated Actions <td> -->
                                        <td>
                                            <div style="display:flex; gap:8px; justify-content:flex-end;">
                                                <button type="button" class="icon-btn" title="Edit"
                                                    onclick="openEditModal(<?= (int)$code['code_id'] ?>)">
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                                                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                                                    </svg>
                                                </button>
                                                <button class="icon-btn" title="Delete"
                                                    style="color:var(--danger); border-color:#fca5a5;"
                                                    onclick="confirmDelete(<?= (int)$code['code_id'] ?>)">
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                        <polyline points="3 6 5 6 21 6"/>
                                                        <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
                                                        <path d="M10 11v6"/><path d="M14 11v6"/>
                                                    </svg>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- ── Mobile Card List ── -->
                <div class="mobile-list" id="mobileList">
                    <?php if (empty($bill_codes)): ?>
                        <p style="text-align:center; color:var(--text-muted); padding:24px 0; font-size:.85rem;">
                            No bill codes found. Add one above.
                        </p>
                    <?php else: ?>
                        <?php foreach ($bill_codes as $code): ?>
                            <div class="bc-card mobile-card"
                                 data-search="<?= e(strtolower(($code['code'] ?? '') . ' ' . ($code['description'] ?? '') . ' ' . ($code['type'] ?? '') . ' ' . ($code['item_code'] ?? '') . ' ' . ($code['item_name'] ?? ''))) ?>">

                                <div class="bc-card-top">
                                    <div>
                                        <div class="bc-card-title"><?= e($code['code']) ?></div>
                                        <div class="bc-card-desc"><?= e($code['description']) ?></div>
                                    </div>
                                    <div class="bc-card-actions">
                                        <button type="button" class="icon-btn" title="Edit"
                                            onclick="openEditModal(<?= (int)$code['code_id'] ?>)">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                                                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                                            </svg>
                                        </button>
                                        <button class="icon-btn" title="Delete"
                                            style="color:var(--danger); border-color:#fca5a5;"
                                            onclick="confirmDelete(<?= (int)$code['code_id'] ?>)">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <polyline points="3 6 5 6 21 6"/>
                                                <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
                                                <path d="M10 11v6"/><path d="M14 11v6"/>
                                            </svg>
                                        </button>
                                    </div>
                                </div>

                                <div class="bc-card-body">
                                    <div class="bc-card-field">
                                        <label>Type</label>
                                        <span class="badge badge-type"><?= e($code['type']) ?></span>
                                    </div>
                                    <div class="bc-card-field">
                                        <label>Sales Price</label>
                                        <span class="mono-bold" style="color:var(--success);">₱ <?= number_format((float)($code['sales_price'] ?? 0), 2) ?></span>
                                    </div>
                                    <?php if ($code['type'] === 'Inventory'): ?>
                                        <div class="bc-card-field">
                                            <label>Cost Price</label>
                                            <span class="mono" style="color:var(--text-secondary);">₱ <?= number_format((float)($code['cost_price'] ?? 0), 2) ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <div class="bc-card-field">
                                        <label>Discount</label>
                                        <?php if ($code['type'] === 'Inventory'): ?>
                                            <span class="badge badge-na">N/A</span>
                                        <?php elseif (!empty($code['allow_member_discount'])): ?>
                                            <span class="badge badge-discount-on">✓ Allowed</span>
                                        <?php else: ?>
                                            <span class="badge badge-discount-off">— Off</span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="bc-gl-list">
                                    <div class="bc-gl-row">
                                        <span class="badge badge-dr">DR</span>
                                        <?= e($code['ar_account'] ?? 'None') ?> — <?= e($code['ar_name'] ?? 'N/A') ?>
                                    </div>
                                    <div class="bc-gl-row">
                                        <span class="badge badge-cr">CR</span>
                                        <?= e($code['income_account'] ?? 'None') ?> — <?= e($code['inc_name'] ?? 'N/A') ?>
                                    </div>
                                    <?php if ($code['type'] === 'Inventory'): ?>
                                        <div class="bc-gl-row">
                                            <span class="badge badge-dr">DR</span>
                                            <?= e($code['cogs_account'] ?? 'None') ?> — <?= e($code['cogs_name'] ?? 'N/A') ?> (info)
                                        </div>
                                        <div class="bc-gl-row">
                                            <span class="badge badge-cr">CR</span>
                                            <?= e($code['asset_account'] ?? 'None') ?> — <?= e($code['ast_name'] ?? 'N/A') ?> (info)
                                        </div>
                                        <?php if (!empty($code['item_id']) && !empty($code['item_code'])): ?>
                                            <div class="bc-gl-row item-link">Item: <strong><?= e($code['item_code']) ?></strong> <?= e($code['item_name']) ?>, on hand <?= number_format((float)$code['item_on_hand'], 2) ?>, avg cost <?= number_format((float)$code['item_avg_cost'], 2) ?></div>
                                        <?php else: ?>
                                            <div class="bc-gl-row item-link missing">Not linked to an item of the Item List</div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div><!-- end Active Bill Codes card -->

        </main>
    </div><!-- end main-content -->

    <!-- ── Edit Modal ─────────────────────────────────────────── -->
    <div class="modal-overlay" id="editModal" onclick="handleOverlayClick(event)">
        <div class="modal-box">
            <div class="modal-header">
                <h2 class="title" style="font-size:1.15rem;">Edit Bill Code</h2>
                <button class="modal-close-btn" onclick="closeEditModal()" aria-label="Close">×</button>
            </div>
            <div class="modal-body">
                <form action="../process/editBillCode.php" method="POST" id="editForm"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="code_id" id="edit_code_id">

                    <div class="form-grid">
                        <div class="form-group col-span-2">
                            <label>Code Name</label>
                            <input type="text" name="code" id="edit_code" class="form-input" style="text-transform:uppercase;" required>
                        </div>
                        <div class="form-group col-span-2">
                            <label>Long Description</label>
                            <input type="text" name="description" id="edit_description" class="form-input">
                        </div>
                        <div class="form-group">
                            <label>Bill Type</label>
                            <select name="type" id="edit_type" class="form-input" required onchange="toggleAccountFields('edit')">
                                <option value="Service">Service</option>
                                <option value="Inventory">Inventory</option>
                                <option value="Charges">Charges</option>
                            </select>
                        </div>
                        <div class="form-group col-span-3 edit-inv-only" style="display:none;">
                            <label>Item (Item List)</label>
                            <select name="item_id" id="edit_item_id" class="form-input">
                                <option value="">Select the item this code sells...</option>
                                <?php foreach ($inventory_items as $it): ?>
                                    <option value="<?= e($it['id']) ?>"><?= e($it['code']) ?> - <?= e($it['name']) ?> (on hand <?= number_format((float)$it['on_hand'], 2) ?><?= $it['unit'] !== null && $it['unit'] !== '' ? ' ' . e($it['unit']) : '' ?>, avg cost <?= number_format((float)$it['avg_cost'], 2) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            <span class="field-note">Required for an Inventory bill code. Stock, average cost and inventory accounts come from this item of the cooperative Item List.</span>
                        </div>
                    </div>

                    <div class="accnt-box">
                        <div class="accnt-box-title">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
                            GL Mapping
                        </div>
                        <div style="font-size:.72rem; color:var(--text-muted); margin:-4px 0 12px; line-height:1.5;">
                            The currently saved Debit and Credit accounts are selected automatically when this bill code is edited.
                        </div>
                        <div class="form-grid" style="margin-bottom:0;">
                            <div class="form-group">
                                <label>A/R Account <span class="badge badge-dr">DR</span></label>
                                <select name="ar_account" id="edit_ar" class="form-input" required>
                                    <option value="">Select...</option>
                                    <?php foreach ($gl_accounts as $gl): ?>
                                        <option value="<?= e($gl['AccntID']) ?>"><?= e($gl['AccntID']) ?> — <?= e($gl['AccntName']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Income Account <span class="badge badge-cr">CR</span></label>
                                <select name="income_account" id="edit_inc" class="form-input" required>
                                    <option value="">Select...</option>
                                    <?php foreach ($gl_accounts as $gl): ?>
                                        <option value="<?= e($gl['AccntID']) ?>"><?= e($gl['AccntID']) ?> — <?= e($gl['AccntName']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group edit-inv-only" style="display:none;">
                                <label>COGS Account <span class="badge badge-dr">DR</span></label>
                                <span class="field-note">Informational: the item's own cost of goods sold account posts.</span>
                                <select name="cogs_account" id="edit_cogs" class="form-input">
                                    <option value="">Select...</option>
                                    <?php foreach ($gl_accounts as $gl): ?>
                                        <option value="<?= e($gl['AccntID']) ?>"><?= e($gl['AccntID']) ?> — <?= e($gl['AccntName']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group edit-inv-only" style="display:none;">
                                <label>Asset Account <span class="badge badge-cr">CR</span></label>
                                <span class="field-note">Informational: the item's own inventory asset account posts.</span>
                                <select name="asset_account" id="edit_ast" class="form-input">
                                    <option value="">Select...</option>
                                    <?php foreach ($gl_accounts as $gl): ?>
                                        <option value="<?= e($gl['AccntID']) ?>"><?= e($gl['AccntID']) ?> — <?= e($gl['AccntName']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="form-grid" style="margin-top:16px;">
                        <div class="form-group">
                            <label>Sales Price (₱)</label>
                            <input type="number" name="sales_price" id="edit_sales" class="form-input mono" step="0.01" required>
                        </div>
                        <div class="form-group edit-inv-only" style="display:none;">
                            <label>Cost Price (₱)</label>
                            <input type="number" name="cost_price" id="edit_cost" class="form-input mono" step="0.01">
                            <span class="field-note">Informational: bills are costed at the item's average cost.</span>
                        </div>
                    </div>

                    <label class="discount-option">
                        <input type="checkbox" name="allow_member_discount" id="edit_allow_member_discount" value="1">
                        <span>
                            <strong>Allow Member Discount</strong><br>
                            Enable this only if Senior Citizen / PWD / other privileges may apply to this bill code.
                        </span>
                    </label>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Cancel</button>
                <button type="submit" form="editForm" class="btn btn-primary">Save Changes</button>
            </div>
        </div>
    </div>

    <!-- Delete form (hidden) -->
    <form id="deleteForm" action="../process/deleteBillCode.php" method="POST" style="display:none;"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="code_id" id="delete_code_id">
    </form>

    <script>
        const billCodeEditData = <?= json_encode(
            $bill_code_edit_data,
            JSON_UNESCAPED_UNICODE
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
            | JSON_INVALID_UTF8_SUBSTITUTE
        ) ?: '{}' ?>;

        /* ── Account field visibility ────────────────────────── */
        function toggleAccountFields(prefix) {
            const type = document.getElementById(prefix + '_type').value;
            const isInventory = (type === 'Inventory');
            document.querySelectorAll('.' + prefix + '-inv-only').forEach(el => {
                el.style.display = isInventory ? 'flex' : 'none';
            });
            // an Inventory bill code must name the item of the Item List it sells
            const itemSelect = document.getElementById(prefix + '_item_id');
            if (itemSelect) {
                itemSelect.required = isInventory;
                if (!isInventory) itemSelect.value = '';
            }
        }

        function selectCurrentItem(selectId, itemId, itemLabel) {
            const select = document.getElementById(selectId);
            if (!select) return;
            Array.from(select.options).forEach(function (item) {
                if (item.dataset.currentMapping === '1') item.remove();
            });
            const value = String(itemId ?? '').trim();
            if (value === '') { select.value = ''; return; }
            let option = Array.from(select.options).find(item => String(item.value).trim() === value);
            if (!option) {
                // the saved item is no longer an active inventory item: keep it selectable so the mapping is not lost by mistake
                option = document.createElement('option');
                option.value = value;
                option.textContent = (String(itemLabel ?? '').trim() || ('Item #' + value)) + ' (inactive item)';
                option.dataset.currentMapping = '1';
                select.appendChild(option);
            }
            option.selected = true;
            select.value = value;
        }

        /* ── Edit Modal ──────────────────────────────────────── */
        function selectCurrentGlAccount(selectId, accountId, accountName, fallbackLabel) {
            const select = document.getElementById(selectId);
            if (!select) return;

            const value = String(accountId ?? '').trim();

            // Remove only temporary options created for an earlier record.
            // Permanent server-rendered GL options are never touched.
            Array.from(select.options).forEach(function (item) {
                if (item.dataset.currentMapping === '1') item.remove();
            });

            if (value === '') {
                select.value = '';
                return;
            }

            // Select the exact currently stored GL account. Using an explicit
            // option lookup is more reliable than assuming select.value alone
            // will work when the option list was filtered.
            let option = Array.from(select.options).find(function (item) {
                return String(item.value).trim() === value;
            });

            // Defensive fallback for legacy/current mappings that are valid in
            // bill_codes but are not returned by the normal dropdown filter.
            if (!option) {
                option = document.createElement('option');
                option.value = value;
                option.textContent = value + ' — ' + (
                    String(accountName ?? '').trim()
                    || fallbackLabel
                    || 'Current saved mapping'
                );
                option.dataset.currentMapping = '1';
                select.appendChild(option);
            }

            option.selected = true;
            select.value = value;

            // Guard against browser/plugin code resetting the field during the
            // same click. selectedIndex directly confirms the exact option.
            select.selectedIndex = Array.from(select.options).indexOf(option);
        }

        function openEditModal(id) {
            const record = billCodeEditData[String(id)];
            if (!record) {
                alert('This bill code could not be loaded for editing. Refresh the page and try again.');
                return;
            }

            document.getElementById('edit_code_id').value      = record.code_id;
            document.getElementById('edit_code').value         = record.code;
            document.getElementById('edit_description').value  = record.description;
            document.getElementById('edit_type').value         = record.type;
            document.getElementById('edit_sales').value        = record.sales_price;
            document.getElementById('edit_cost').value         = record.cost_price;
            document.getElementById('edit_allow_member_discount').checked =
                parseInt(record.allow_member_discount || 0, 10) === 1;

            // Automatically preselect the bill code's saved accounting map.
            selectCurrentGlAccount(
                'edit_ar', record.ar_account, record.ar_name, 'Current Debit / A/R mapping'
            );
            selectCurrentGlAccount(
                'edit_inc', record.income_account, record.income_name, 'Current Credit / Income mapping'
            );
            selectCurrentGlAccount(
                'edit_cogs', record.cogs_account, record.cogs_name, 'Current COGS mapping'
            );
            selectCurrentGlAccount(
                'edit_ast', record.asset_account, record.asset_name, 'Current Asset mapping'
            );
            selectCurrentItem('edit_item_id', record.item_id, record.item_label);

            toggleAccountFields('edit');
            document.getElementById('editModal').classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeEditModal() {
            document.getElementById('editModal').classList.remove('active');
            document.body.style.overflow = '';
        }

        function handleOverlayClick(e) {
            if (e.target === document.getElementById('editModal')) closeEditModal();
        }

        /* Escape key closes modal */
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') closeEditModal();
        });

        /* ── Delete ──────────────────────────────────────────── */
        function confirmDelete(id) {
            AppDialog.confirm('Delete this bill code? This cannot be undone.', { title: 'Delete bill code', ok: 'Delete', tone: 'danger' }).then((yes) => {
                if (!yes) return;
                document.getElementById('delete_code_id').value = id;
                document.getElementById('deleteForm').submit();
            });
        }

        /* ── Live Search ─────────────────────────────────────── */
        function filterTable() {
            const q = document.getElementById('searchInput').value.toLowerCase().trim();

            /* Desktop table rows */
            document.querySelectorAll('.bc-row').forEach(row => {
                row.style.display = (!q || row.dataset.search.includes(q)) ? '' : 'none';
            });

            /* Mobile cards */
            document.querySelectorAll('.mobile-card').forEach(card => {
                card.style.display = (!q || card.dataset.search.includes(q)) ? '' : 'none';
            });
        }

        /* ── Code → Description Auto-fill ───────────────────── */
        function codeNameWhileTyping(v) {
            return (v || '').replace(/[\t\r\n]+/g, ' ').toUpperCase();
        }
        function cleanCodeName(v) {
            return codeNameWhileTyping(v).replace(/\s+/g, ' ').trim();
        }
        function titleCase(v) {
            return cleanCodeName(v).toLowerCase().replace(/\b\w/g, c => c.toUpperCase());
        }

        function setupCodeDescriptionSync(codeId, descId) {
            const codeEl = document.getElementById(codeId);
            const descEl = document.getElementById(descId);
            if (!codeEl || !descEl) return;

            descEl.dataset.autoSynced = '1';

            descEl.addEventListener('input', function () {
                const expected = titleCase(codeEl.value);
                this.dataset.autoSynced = (this.value.trim() === '' || this.value.trim() === expected) ? '1' : '0';
            });

            codeEl.addEventListener('input', function () {
                // Preserve a trailing space while the user is typing so multi-word
                // names such as WATER METER can be entered normally.
                const typed = codeNameWhileTyping(this.value);
                this.value = typed;
                if (descEl.dataset.autoSynced === '1' || descEl.value.trim() === '') {
                    descEl.value = titleCase(typed);
                    descEl.dataset.autoSynced = '1';
                }
            });

            codeEl.addEventListener('blur', function () {
                this.value = cleanCodeName(this.value);
            });
        }

        function clearBillCodeForm() {
            document.getElementById('billCodeForm').reset();
            toggleAccountFields('add');
            const addDesc = document.getElementById('add_description');
            if (addDesc) { addDesc.dataset.autoSynced = '1'; addDesc.value = ''; }
        }

        document.addEventListener('DOMContentLoaded', function () {
            setupCodeDescriptionSync('add_code',  'add_description');
            setupCodeDescriptionSync('edit_code', 'edit_description');

            ['billCodeForm', 'editForm'].forEach(function (formId) {
                const form = document.getElementById(formId);
                if (!form) return;
                form.addEventListener('submit', function () {
                    const codeInput = this.querySelector('input[name="code"]');
                    if (codeInput) codeInput.value = cleanCodeName(codeInput.value);
                });
            });
        });
    </script>
</body>
</html>