<?php
/* AUTH_GUARD_START */
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../process/accessControlHelpers.php';
$__authGuardPdo = getDB();
requirePageAccess($__authGuardPdo, basename(__FILE__, '.php'));
unset($__authGuardPdo);
/* AUTH_GUARD_END */


require_once '../database/config.php';
require_once '../common/discountPrivilegeBillingHelpers.php';
require_once '../common/waterBillCodeExclusionHelpers.php';
require_once '../process/organizationUnitHelpers.php';

require_once __DIR__ . '/../database/config.php';

if (!function_exists('e')) {
    function e($value): string { return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

$current_page = basename($_SERVER['PHP_SELF'], '.php');

// ==========================================
// DYNAMIC DATA FETCHING
// ==========================================
$members = [];
$bill_codes = [];
$active_installments = [];
$all_schedules = []; 

$pdo = getDB();

if (isset($pdo)) {
    try {
        $mem_stmt = $pdo->query("SELECT pkey, CONCAT_WS(', ', NULLIF(lastname, ''), NULLIF(firstname, '')) AS full_name FROM water_accounts WHERE status = 'A' ORDER BY lastname ASC");
        $members = $mem_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch bill codes with inventory metadata and current quantity balance
        $excludeWaterBillingBillCode = water_billing_excluded_bill_code_where($pdo, 'bc');

        $code_stmt = $pdo->query("
            SELECT
                bc.code_id,
                bc.code,
                bc.description,
                bc.type,
                bc.sales_price,
                CASE WHEN bc.type = 'Inventory' AND it.id IS NOT NULL THEN it.avg_cost ELSE bc.cost_price END AS cost_price,
                bc.ar_account,
                bc.income_account,
                bc.cogs_account,
                bc.asset_account,
                bc.item_id,
                it.code AS item_code,
                COALESCE((SELECT SUM(s.qty_on_hand) FROM item_site_stock s WHERE s.item_id = it.id), 0) AS current_qty
            FROM bill_codes bc
            LEFT JOIN items it ON it.id = bc.item_id
            WHERE 1 = 1 {$excludeWaterBillingBillCode}
            ORDER BY bc.code ASC
        ");
        $bill_codes = $code_stmt->fetchAll(PDO::FETCH_ASSOC);

        $inst_stmt = $pdo->query("
            SELECT
                i.*,
                m.pkey AS accnt_no,
                CONCAT_WS(', ', NULLIF(m.lastname, ''), NULLIF(m.firstname, '')) AS member_name,
                c.code,
                c.type AS bill_code_type,
                c.sales_price,
                CASE WHEN c.type = 'Inventory' AND it.id IS NOT NULL THEN it.avg_cost ELSE c.cost_price END AS cost_price,
                COALESCE((SELECT SUM(s.qty_on_hand) FROM item_site_stock s WHERE s.item_id = it.id), 0) AS current_qty
            FROM installment_bills i
            JOIN water_accounts m ON i.member_id = m.pkey
            JOIN bill_codes c ON i.bill_code_id = c.code_id
            LEFT JOIN items it ON it.id = c.item_id
            ORDER BY i.created_at DESC
        ");
        $active_installments = $inst_stmt->fetchAll(PDO::FETCH_ASSOC);

        $sched_stmt = $pdo->query("
            SELECT s.*, COALESCE(NULLIF(u.description, ''), u.name) AS marked_by_name 
            FROM installment_schedules s 
            LEFT JOIN operator u ON s.marked_by = u.pkey 
            ORDER BY s.due_date ASC
        ");
        while ($row = $sched_stmt->fetch(PDO::FETCH_ASSOC)) {
            $all_schedules[$row['installment_id']][] = $row;
        }

    } catch (PDOException $e) {
        error_log("Fetch Error: " . $e->getMessage());
    }
}

$member_discount_map = [];
$installment_discount_rules = [];

try {
    $rows = $pdo->query("
        SELECT member_id, discount_id
        FROM discounted_members
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        $mid = (int)$row['member_id'];
        if (!isset($member_discount_map[$mid])) {
            $member_discount_map[$mid] = [];
        }
        $member_discount_map[$mid][] = (int)$row['discount_id'];
    }
} catch (Throwable $e) {
    $member_discount_map = [];
}

try {
    $installment_discount_rules = $pdo->query("
        SELECT
            d.discount_id AS discount_rule_id,
            d.discount_id,
            bc.code_id AS bill_code_id,
            'installment' AS module_context,
            d.installment_discount_method AS discount_method,
            d.installment_discount_value AS discount_value,
            d.installment_max_discount_amount AS max_discount_amount
        FROM discounts d
        CROSS JOIN bill_codes bc
        WHERE d.is_active = 1
          AND d.installment_discount_active = 1
          AND d.installment_discount_method IN ('percent', 'fixed')
          AND bc.allow_member_discount = 1
          AND bc.type <> 'Inventory'
        ORDER BY d.discount_id ASC, bc.code_id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $installment_discount_rules = [];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../common/fonts.css">
    <title>Installment Bills - <?= htmlspecialchars(app_name(), ENT_QUOTES, 'UTF-8') ?></title>
    
    <link rel="stylesheet" href="../../assets/vendor/flatpickr/flatpickr.min.css">
    
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
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
            --warning: #d97706;
            --warning-bg: #fffbeb;
            --radius-sm: 8px;
            --radius: 14px;
            --radius-lg: 20px;
            --shadow-sm: 0 1px 3px rgba(0,0,0,.06), 0 1px 2px rgba(0,0,0,.04);
            --shadow-lg: 0 20px 60px rgba(0,0,0,.18);
        }
        body { font-family: 'Sora', sans-serif; background: var(--off-white); color: var(--text-primary); }
        
        .page { padding: 32px 32px 60px; max-width: 1400px; margin: 0 auto; animation: fadeIn .4s ease both; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }

        .header { display: flex; flex-direction: column; align-items: flex-start; margin-bottom: 24px; gap: 12px; }
        .title { font-size: 1.5rem; font-weight: 700; line-height: 1.2; }
        .subtitle { font-size: .875rem; color: var(--text-secondary); margin-top: 4px; }
        
        .alert { padding: 12px 16px; border-radius: var(--radius-sm); margin-bottom: 20px; font-size: .875rem; font-weight: 600; display: flex; align-items: center; gap: 8px; }
        .alert-success { background: var(--success-bg); border: 1px solid var(--success); color: var(--success); }
        .alert-danger { background: var(--danger-bg); border: 1px solid var(--danger); color: var(--danger); }

        .card { background: var(--white); border: 1px solid var(--border); border-radius: var(--radius); padding: 24px; margin-bottom: 24px; box-shadow: var(--shadow-sm); }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid var(--border); }
        .card-title { font-size: 1.1rem; font-weight: 600; }
        
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 20px; min-width: 0; }
        .form-grid > * { min-width: 0; }
        .form-group { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
        label { font-size: .75rem; font-weight: 700; color: var(--text-secondary); text-transform: uppercase; letter-spacing: .05em; line-height: 1.25; }
        input, select { width: 100%; min-width: 0; max-width: 100%; padding: 9px 12px; border: 1.5px solid var(--border); border-radius: var(--radius-sm); font-family: inherit; font-size: .875rem; outline: none; background: var(--white); transition: border-color .2s; }
        input:focus, select:focus { border-color: var(--border-focus); }
        select { background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%239ca3af' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 12px center; padding-right: 32px; appearance: none; }


        /* Installment detail controls must remain inside their own grid cells. */
        .installment-details-grid {
            grid-template-columns:
                minmax(240px, 1.35fr)
                minmax(175px, .95fr)
                minmax(150px, .9fr)
                minmax(155px, .9fr)
                minmax(155px, .9fr)
                minmax(170px, .95fr);
            align-items: start;
        }
        .installment-details-grid .form-group,
        .installment-details-grid input,
        .installment-details-grid select {
            min-width: 0;
            max-width: 100%;
        }
        #billCodeSelect {
            display: block;
            width: 100%;
            overflow: hidden;
            white-space: nowrap;
            text-overflow: ellipsis;
            padding-right: 32px;
        }
        #inventoryStockHint {
            overflow-wrap: anywhere;
        }
        @media (max-width: 1180px) {
            .installment-details-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }
        @media (max-width: 760px) {
            .installment-details-grid {
                grid-template-columns: minmax(0, 1fr);
            }
        }
        input[readonly] { background: var(--off-white); color: var(--text-secondary); cursor: not-allowed; font-weight: 600; }
        
        .editable-amount { border: 1.5px solid var(--border); border-radius: 6px; padding: 6px 10px; width: 130px; font-family: 'JetBrains Mono', monospace; font-weight: 600; outline: none; transition: border-color 0.2s; }
        .editable-amount:focus { border-color: var(--accent); }

        .btn-group { display: flex; gap: 12px; margin-top: 16px; }
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 0 16px; height: 38px; border: none; border-radius: var(--radius-sm); font-family: inherit; font-size: .8rem; font-weight: 600; cursor: pointer; text-decoration: none; transition: background .2s, border-color .2s, transform .1s; white-space: nowrap; }
        .btn:active { transform: scale(0.98); }
        .btn-primary { background: var(--accent); color: var(--white); }
        .btn-primary:hover { background: #0f0f1a; }
        .btn-secondary { background: var(--white); color: var(--text-primary); border: 1.5px solid var(--border); }
        .btn-secondary:hover { border-color: var(--border-focus); background: var(--off-white); }
        .btn-success { background: var(--success); color: var(--white); }
        
        .icon-btn { width: 32px; height: 32px; border-radius: 8px; border: 1.5px solid var(--border); background: var(--white); display: flex; align-items: center; justify-content: center; cursor: pointer; color: var(--text-secondary); transition: all .2s; text-decoration: none; flex-shrink: 0; }
        .icon-btn:hover { border-color: var(--border-focus); color: var(--text-primary); background: var(--off-white); transform: translateY(-1px); }
        .icon-btn svg { width: 14px; height: 14px; }

        .card-table-wrap { border: 1px solid var(--border); border-radius: var(--radius-sm); overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: .85rem; white-space: nowrap; }
        th { text-align: left; padding: 12px 16px; color: var(--text-secondary); border-bottom: 1.5px solid var(--border); font-weight: 600; font-size: .72rem; text-transform: uppercase; letter-spacing: .04em; }
        td { padding: 14px 16px; border-bottom: 1px solid var(--border); vertical-align: middle; }
        tr:last-child td { border-bottom: none; }
        tbody tr:hover { background: var(--off-white); }
        .mono { font-family: 'JetBrains Mono', monospace; font-size: .8rem; }
        
        .badge { display: inline-flex; align-items: center; padding: 4px 10px; border-radius: 20px; font-size: .68rem; font-weight: 700; letter-spacing: .02em; text-transform: uppercase; }
        .badge-success { background: var(--success-bg); color: var(--success); }
        .badge-danger { background: var(--danger-bg); color: var(--danger); }
        .badge-warning { background: var(--warning-bg); color: var(--warning); border: 1px solid #fcd34d; }
        .badge-cancelled { background: var(--border); color: var(--text-secondary); }

        .preview-container { background: var(--off-white); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 16px; display: none; }
        .preview-container.active { display: block; animation: fadeIn 0.3s ease; }
        
        .modal-overlay { position: fixed; inset: 0; background: rgba(10,10,20,0.55); z-index: 1060; display: none; align-items: center; justify-content: center; opacity: 0; transition: opacity .3s ease; backdrop-filter: blur(4px); padding: 16px; }
        .modal-overlay.active { display: flex; opacity: 1; }
        .modal-box { background: var(--white); border-radius: var(--radius-lg); width: 100%; max-width: 400px; box-shadow: var(--shadow-lg); transform: translateY(24px) scale(0.97); opacity: 0; transition: transform 0.35s, opacity 0.35s; display: flex; flex-direction: column; }
        .modal-overlay.active .modal-box { transform: translateY(0) scale(1); opacity: 1; }
        .modal-box.large { max-width: 900px; } 
        
        .modal-header { padding: 20px 24px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
        .modal-body { padding: 24px; max-height: 70vh; overflow-y: auto; }
        .modal-close-btn { background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-muted); padding: 0; line-height: 1; }
        .modal-close-btn:hover { color: var(--text-primary); }
        
        .flatpickr-input { font-family: 'JetBrains Mono', monospace; font-size: .875rem; }
        .field-hint { font-size: .72rem; color: var(--text-muted); line-height: 1.45; margin-top: 2px; }
    .discount-info{margin:10px 0 16px;padding:12px 14px;background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;border-radius:10px;font-size:.82rem;line-height:1.5;font-weight:700;}
tr.deleted-row td{ text-decoration:line-through; color:#9ca3af; background:#f9fafb; } tr.deleted-row .badge, tr.deleted-row button{ text-decoration:none; }
</style>
</head>
<body>

    <?php require_once '../common/navbar.php'; ?>

    <div class="main-content">
        <main class="page">
<div class="discount-info">Discount privileges are now applied automatically when the member is eligible, the Bill Code allows member discount, and a rule exists in Discount Privilege Rules.</div>
            <div class="header">
                <a href="billing_management.php" style="display:inline-flex; align-items:center; gap:6px; color:var(--text-secondary); text-decoration:none; font-size:.8rem; font-weight:600; margin-bottom: 4px;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="14" height="14"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                    Back to Billing Management
                </a>

                <div style="width: 100%;">
                    <h1 class="title">Installment Bills</h1>
                    <p class="subtitle">Manage amortized payments, flexible schedules, and bill types</p>
                </div>
            </div>

            <?php if (isset($_SESSION['success_msg'])): ?>
                <div class="alert alert-success">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg>
                    <?= e($_SESSION['success_msg']); unset($_SESSION['success_msg']); ?>
                </div>
            <?php endif; ?>
            <?php if (isset($_SESSION['error_msg'])): ?>
                <div class="alert alert-danger">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                    <?= e($_SESSION['error_msg']); unset($_SESSION['error_msg']); ?>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Create Installment Contract</h2>
                    <button type="button" class="btn btn-secondary" onclick="openBatchInstallmentModal()">Batch Enrollment</button>
                </div>
                
                <form id="scheduleForm" action="../process/addInstallmentBill.php" method="POST"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                    <div class="form-grid">
                        <div class="form-group" style="grid-column: span 2;">
                            <label>Consumer Account</label>
                            <select name="member_id" class="form-input" required>
                                <option value="" disabled selected>Search & Select Member...</option>
                                <?php foreach ($members as $mem): ?>
                                    <option value="<?= e($mem['pkey']) ?>">
                                        <?= e($mem['pkey']) ?> — <?= e($mem['full_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div style="height: 1px; background: var(--border); margin: 20px 0;"></div>

                    <div class="form-grid installment-details-grid">
                        <div class="form-group">
                            <label>Bill Code</label>
                            <select name="bill_code_id" id="billCodeSelect" class="form-input" required onchange="autofillAmount()">
                                <option value="" disabled selected>Select Item...</option>
                                <?php foreach ($bill_codes as $code): ?>
                                    <option
                                        value="<?= e($code['code_id']) ?>"
                                        data-amount="<?= e((float)($code['sales_price'] ?? 0)) ?>"
                                        data-type="<?= e($code['type'] ?? '') ?>"
                                        data-cost="<?= e((float)($code['cost_price'] ?? 0)) ?>"
                                        data-stock="<?= e((float)($code['current_qty'] ?? 0)) ?>"
                                        data-item="<?= e($code['item_id'] ?? '') ?>"
                                    >
                                        <?= e($code['code']) ?><?= ($code['type'] ?? '') === 'Inventory' && empty($code['item_id']) ? ' (not linked to an item)' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" id="inventoryQtyGroup" style="display:none;">
                            <label>Inventory Quantity to Deduct</label>
                            <input
                                type="number"
                                name="quantity"
                                id="inventoryQuantity"
                                value="1"
                                min="0.01"
                                step="0.01"
                                class="form-input mono"
                                oninput="recalculateInventoryInstallmentAmount()"
                            >
                            <span class="field-hint" id="inventoryStockHint">Select an inventory bill code to see available balance.</span>
                        </div>
                        <div class="form-group">
                            <label>Total Amount (₱)</label>
                            <input type="number" name="total_amount" id="totalAmount" placeholder="0.00" step="0.01" class="form-input mono" required oninput="closeSchedule()">
                        </div>
                        <div class="form-group">
                            <label>Term (Total Months)</label>
                            <input type="number" name="term" id="term" value="6" min="1" class="form-input mono" oninput="updatePaymentModes()" required>
                        </div>
                        <div class="form-group">
                            <label>Payment Mode</label>
                            <select name="payment_mode" id="payMode" class="form-input" required>
                                </select>
                        </div>
                        <div class="form-group">
                            <label>Amortization Type</label>
                            <select name="amortization_type" id="amortType" class="form-input" required onchange="generateSchedule()">
                                <option value="fixed">Fixed</option>
                                <option value="flexible">Flexible</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Start Date</label>
                            <input type="text" name="start_date" id="startDate" class="form-input" placeholder="mm/dd/yyyy" required>
                        </div>
                        <div class="form-group">
                            <?= render_water_class_field(null, true, 'classId', 'class_id', 'form-input') ?>
                        </div>
                    </div>

                    <div class="btn-group">
                        <button type="button" class="btn btn-secondary" onclick="generateSchedule()">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                            Generate Schedule Preview
                        </button>
                    </div>

                    <div id="schedulePreview" class="preview-container" style="margin-top: 24px;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 16px; flex-wrap: wrap; gap: 8px;">
                            <h3 style="font-size: 1rem;">Amortization Schedule</h3>
                            <div style="display: flex; gap: 12px; align-items: center;">
                                <span id="scheduleTotalBadge" style="font-size: .85rem; font-weight: 700;"></span>
                                <span id="scheduleStatusBadge" style="font-size: .75rem; font-weight: 700; padding: 4px 10px; border-radius: 20px; background: var(--white); border: 1px solid var(--border);"></span>
                            </div>
                        </div>
                        
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th style="width: 10%;">#</th>
                                        <th style="width: 45%;">Due Date</th>
                                        <th>Amount to Bill</th>
                                    </tr>
                                </thead>
                                <tbody id="scheduleBody">
                                    </tbody>
                            </table>
                        </div>
                        
                        <div class="btn-group" style="justify-content: flex-end; margin-top: 16px;">
                            <button type="button" class="btn btn-secondary" onclick="closeSchedule()">Cancel</button>
                            <button type="submit" class="btn btn-success">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                                Save Installment Contract
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <div class="card" style="padding: 0;">
                <div class="card-header" style="margin: 20px 24px 16px;">
                    <h2 class="card-title">Installment Contracts Ledger</h2>
                    <div style="display:flex; gap: 8px;">
                        <input type="search" placeholder="Search accounts..." style="padding: 6px 12px; width: min(240px, 100%);" class="form-input" data-inc-table="#installmentLedgerBody" aria-label="Search the installment contracts">
                    </div>
                </div>
                
                <div class="card-table-wrap" style="border: none; border-radius: 0 0 var(--radius) var(--radius);">
                    <table>
                        <thead>
                            <tr>
                                <th>Start Date</th>
                                <th>Account</th>
                                <th>Category</th>
                                <th>Qty</th>
                                <th>Terms</th>
                                <th>Total Amount</th>
                                <th>Status</th>
                                <th style="text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="installmentLedgerBody">
                            <?php if (empty($active_installments) && isset($pdo)): ?>
                                <tr>
                                    <td colspan="8" style="text-align: center; padding: 30px; color: var(--text-muted);">
                                        No active installment contracts found.
                                    </td>
                                </tr>
                            <?php elseif (!isset($pdo)): ?>
                                <tr>
                                    <td colspan="8" style="text-align: center; padding: 30px; color: var(--danger);">
                                        <strong>Database Connection Missing.</strong>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($active_installments as $inst): ?>
                                    <tr class="<?= ($inst['status'] ?? '') === 'deleted' ? 'deleted-row' : '' ?>">
                                        <td class="mono"><?= date('m/d/Y', strtotime($inst['start_date'])) ?></td>
                                        <td>
                                            <div style="font-weight:600;"><?= e($inst['member_name']) ?></div>
                                            <div class="mono" style="font-size:.7rem; color:var(--text-muted);">#<?= e($inst['accnt_no']) ?></div>
                                        </td>
                                        <td><span style="font-weight:600;"><?= e($inst['code']) ?></span></td>
                                        <td class="mono">
                                            <?php if (($inst['bill_code_type'] ?? '') === 'Inventory'): ?>
                                                <?= number_format((float)($inst['quantity'] ?? 1), 2) ?>
                                            <?php else: ?>
                                                <span style="color:var(--text-muted);">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="mono"><?= e($inst['term']) ?> Months</div>
                                            <div style="font-size:.7rem; color:var(--text-secondary); text-transform: capitalize; margin-top:2px;"><?= str_replace('_', ' ', e($inst['payment_mode'])) ?></div>
                                        </td>
                                        <td class="mono" style="font-weight:600;">₱ <?= number_format($inst['total_amount'], 2) ?></td>
                                        <td>
                                            <?php 
                                                $badgeClass = 'badge-success';
                                                if ($inst['status'] === 'completed') $badgeClass = 'badge-success';
                                                elseif ($inst['status'] === 'cancelled' || $inst['status'] === 'deleted') $badgeClass = 'badge-cancelled';
                                                elseif ($inst['status'] === 'active') $badgeClass = 'badge-warning';
                                            ?>
                                            <span class="badge <?= $badgeClass ?>"><?= ucfirst(e($inst['status'])) ?></span>
                                        </td>
                                        <td>
                                            <div style="display:flex; gap:8px; justify-content: flex-end;">
                                                <button class="icon-btn" title="View Schedules" onclick="openViewModal(<?= $inst['installment_id'] ?>, '<?= e(addslashes((string)($inst['member_name'] ?? ''))) ?>')">
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                                </button>
                                                <button class="icon-btn" title="Change Contract Status" onclick="openStatusModal(<?= $inst['installment_id'] ?>, '<?= e($inst['status']) ?>', <?= (int)($inst['class_id'] ?? 0) ?>)">
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                                </button>
                                                <button class="icon-btn" title="Delete Contract" style="color: var(--danger); border-color: #fca5a5;" onclick="confirmDelete(<?= $inst['installment_id'] ?>)">
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
                                                </button>
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

    <form id="deleteForm" action="../process/deleteInstallmentBill.php" method="POST" style="display: none;"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="installment_id" id="delete_installment_id">
    </form>

    <div class="modal-overlay" id="viewModal">
        <div class="modal-box large">
            <div class="modal-header">
                <h2 class="title" style="font-size: 1.25rem;">Amortization Schedule</h2>
                <button class="modal-close-btn" onclick="closeViewModal()">×</button>
            </div>
            <div class="modal-body">
                <div style="margin-bottom: 16px;">
                    <span style="font-size: .85rem; color: var(--text-secondary);">Consumer Account:</span>
                    <div style="font-size: 1.1rem; font-weight: 700; color: var(--text-primary);" id="view_member_name">--</div>
                </div>

                <div class="card-table-wrap" style="border-radius: var(--radius-sm); border: 1px solid var(--border);">
                    <table>
                        <thead>
                            <tr>
                                <th>Due Date</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Paid At</th>
                                <th>Processed By</th>
                                <th style="text-align: right;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="view_schedule_body">
                            </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="statusModal">
        <div class="modal-box">
            <div class="modal-header">
                <h2 class="title" style="font-size: 1.25rem;">Update Contract Status</h2>
                <button class="modal-close-btn" onclick="closeStatusModal()">×</button>
            </div>
            <div class="modal-body">
                <form action="../process/editInstallmentBill.php" method="POST"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="installment_id" id="status_installment_id">
                    
                    <div class="form-group">
                        <label>Contract Status</label>
                        <select name="status" id="edit_status" class="form-input" required>
                            <option value="active">Active (Pending Payments)</option>
                            <option value="cancelled">Cancelled (Voided)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <?= render_water_class_field(null, true, 'statusClassId', 'class_id', 'form-input', 'Class of the change') ?>
                    </div>

                    <div class="btn-group" style="justify-content: flex-end; margin-top: 16px;">
                        <button type="button" class="btn btn-secondary" onclick="closeStatusModal()">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Status</button>
                    </div>
                </form>
            </div>
        </div>
    </div>


    <div class="modal-overlay" id="batchInstallmentModal">
        <div class="modal-box large">
            <div class="modal-header">
                <h2 class="card-title">Batch Installment Enrollment</h2>
                <button class="modal-close-btn" onclick="closeBatchInstallmentModal()">×</button>
            </div>
            <div class="modal-body">
                <form action="../process/addBatchInstallmentBill.php" method="POST"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                    <div class="form-grid">
                        <div class="form-group" style="grid-column: span 2;">
                            <label>Members</label>
                            <select name="member_ids[]" id="batchInstallmentMembers" multiple size="10" required onchange="updateBatchInstallmentDiscountPreview()">
                                <?php foreach ($members as $mem): ?>
                                    <option value="<?= e($mem['pkey']) ?>"><?= e($mem['pkey']) ?> — <?= e($mem['full_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span class="field-hint">Hold CTRL while clicking to select multiple members.</span>
                        </div>
                        <div class="form-group">
                            <label>Bill Code</label>
                            <select name="bill_code_id" id="batchInstallmentBillCode" required onchange="autofillBatchInstallmentAmount()">
                                <option value="" disabled selected>Select non-inventory bill code...</option>
                                <?php foreach ($bill_codes as $code): ?>
                                    <?php if (($code['type'] ?? '') !== 'Inventory'): ?>
                                        <option value="<?= e($code['code_id']) ?>" data-amount="<?= e((float)($code['sales_price'] ?? 0)) ?>" data-type="<?= e($code['type'] ?? '') ?>"><?= e($code['code']) ?> — ₱<?= number_format((float)($code['sales_price'] ?? 0), 2) ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                            <span class="field-hint">Inventory batch installment is blocked to protect stock quantity balance.</span>
                        </div>
                        <div class="form-group">
                            <label>Total Amount per Member</label>
                            <input type="number" name="total_amount" id="batchInstallmentTotalAmount" step="0.01" min="0.01" required oninput="updateBatchInstallmentDiscountPreview()">
                            <span class="field-hint">Auto-filled from the selected Bill Code sales price. Discount privileges are finalized by the backend.</span>
                        </div>
                        <div class="form-group full">
                            <div id="batchInstallmentDiscountPreview" class="batch-preview" style="display:none;"></div>
                        </div>
                        <div class="form-group">
                            <label>Term (Total Months)</label>
                            <input type="number" name="term" id="batchInstallmentTerm" value="6" min="1" required>
                        </div>
                        <div class="form-group">
                            <label>Payment Mode</label>
                            <select name="payment_mode" id="batchInstallmentPaymentMode" required>
                                <option value="monthly">Monthly</option>
                                <option value="quarterly">Quarterly</option>
                                <option value="semi_annually">Semi-Annually</option>
                                <option value="annually">Annually</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Start Date</label>
                            <input type="date" name="start_date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="form-group">
                            <?= render_water_class_field(null, true, 'batchClassId', 'class_id') ?>
                        </div>
                    </div>
                    <div class="btn-group"><button type="submit" class="btn btn-primary">Create Batch Installments</button></div>
                </form>
            </div>
        </div>
    </div>

    <script src="../../assets/vendor/flatpickr/flatpickr.min.js"></script>
    <script>
        const allSchedules = <?= json_encode($all_schedules, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

        const memberDiscountMap = <?= json_encode($member_discount_map, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        const installmentDiscountRules = <?= json_encode($installment_discount_rules, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        function openBatchInstallmentModal() { document.getElementById('batchInstallmentModal').classList.add('active'); updateBatchInstallmentDiscountPreview(); }
        function closeBatchInstallmentModal() { document.getElementById('batchInstallmentModal').classList.remove('active'); }

        function computeBatchInstallmentDiscount(memberId, billCodeId, grossAmount) {
            const memberDiscounts = memberDiscountMap[String(memberId)] || memberDiscountMap[memberId] || [];
            let best = 0;

            installmentDiscountRules.forEach(rule => {
                if (parseInt(rule.bill_code_id, 10) !== parseInt(billCodeId, 10)) return;
                if (!memberDiscounts.map(Number).includes(parseInt(rule.discount_id, 10))) return;

                let discount = 0;
                const method = String(rule.discount_method || 'none').toLowerCase();
                const value = parseFloat(rule.discount_value || 0);

                if (method === 'percent') {
                    discount = grossAmount * (value / 100);
                } else if (method === 'fixed') {
                    discount = value;
                }

                const maxDiscount = parseFloat(rule.max_discount_amount || 0);
                if (maxDiscount > 0) {
                    discount = Math.min(discount, maxDiscount);
                }

                discount = Math.max(0, Math.min(discount, grossAmount));
                if (discount > best) best = discount;
            });

            return Math.round(best * 100) / 100;
        }

        function updateBatchInstallmentDiscountPreview() {
            const select = document.getElementById('batchInstallmentBillCode');
            const amountInput = document.getElementById('batchInstallmentTotalAmount');
            const membersSelect = document.getElementById('batchInstallmentMembers');
            const preview = document.getElementById('batchInstallmentDiscountPreview');

            if (!select || !amountInput || !membersSelect || !preview || !select.value) {
                if (preview) preview.style.display = 'none';
                return;
            }

            const gross = parseFloat(amountInput.value || '0');
            const selectedMembers = Array.from(membersSelect.selectedOptions || []).map(opt => parseInt(opt.value, 10)).filter(Boolean);

            if (gross <= 0 || selectedMembers.length <= 0) {
                preview.style.display = 'none';
                return;
            }

            let totalGross = 0;
            let totalDiscount = 0;

            selectedMembers.forEach(memberId => {
                totalGross += gross;
                totalDiscount += computeBatchInstallmentDiscount(memberId, select.value, gross);
            });

            const totalNet = Math.max(0, totalGross - totalDiscount);
            const discountLine = totalDiscount > 0
                ? `<strong>Estimated discount:</strong> ₱ ${totalDiscount.toFixed(2)}`
                : `<strong>Estimated discount:</strong> ₱ 0.00`;

            preview.innerHTML = `
                <div><strong>Selected members:</strong> ${selectedMembers.length}</div>
                <div><strong>Gross total:</strong> ₱ ${totalGross.toFixed(2)}</div>
                <div>${discountLine}</div>
                <div><strong>Estimated net total:</strong> ₱ ${totalNet.toFixed(2)}</div>
                <div style="font-size:.72rem;margin-top:4px;">Final discount computation is validated again when saving.</div>
            `;
            preview.style.display = '';
        }

        function autofillBatchInstallmentAmount() {
            const select = document.getElementById('batchInstallmentBillCode');
            const amountInput = document.getElementById('batchInstallmentTotalAmount');

            if (!select || !amountInput || !select.value) return;

            const option = select.options[select.selectedIndex];
            const amount = parseFloat(option.getAttribute('data-amount') || '0');

            if (amount > 0) {
                amountInput.value = amount.toFixed(2);
            }

            updateBatchInstallmentDiscountPreview();
        }
        
        flatpickr("#startDate", {
            dateFormat: "Y-m-d", 
            altInput: true,      
            altFormat: "m/d/Y",  
            defaultDate: "today" 
        });

        function autofillAmount() {
            const selectEl = document.getElementById('billCodeSelect');
            const selectedOption = selectEl.options[selectEl.selectedIndex];
            selectEl.title = selectedOption ? selectedOption.textContent.trim().replace(/\s+/g, ' ') : '';
            const defaultAmount = parseFloat(selectedOption.getAttribute('data-amount')) || 0;
            const billType = selectedOption.getAttribute('data-type') || '';
            const stock = parseFloat(selectedOption.getAttribute('data-stock')) || 0;

            const totalAmountInput = document.getElementById('totalAmount');
            const qtyGroup = document.getElementById('inventoryQtyGroup');
            const qtyInput = document.getElementById('inventoryQuantity');
            const stockHint = document.getElementById('inventoryStockHint');

            if (billType === 'Inventory') {
                qtyGroup.style.display = '';
                qtyInput.required = true;

                if (!qtyInput.value || parseFloat(qtyInput.value) <= 0) {
                    qtyInput.value = '1';
                }

                stockHint.textContent = selectedOption.getAttribute('data-item')
                    ? `Available quantity balance: ${stock.toFixed(2)} (cooperative inventory, average cost ${(parseFloat(selectedOption.getAttribute('data-cost')) || 0).toFixed(2)})`
                    : 'This inventory bill code is not linked to an item of the Item List (Bill Codes). It cannot be billed until it is.';
                totalAmountInput.value = (defaultAmount * (parseFloat(qtyInput.value) || 1)).toFixed(2);
            } else {
                qtyGroup.style.display = 'none';
                qtyInput.required = false;
                qtyInput.value = '1';

                if (defaultAmount > 0) {
                    totalAmountInput.value = defaultAmount.toFixed(2);
                }
            }

            closeSchedule();
        }

        function recalculateInventoryInstallmentAmount() {
            const selectEl = document.getElementById('billCodeSelect');
            if (!selectEl.value) return;

            const selectedOption = selectEl.options[selectEl.selectedIndex];
            const billType = selectedOption.getAttribute('data-type') || '';

            if (billType !== 'Inventory') return;

            const unitSalesPrice = parseFloat(selectedOption.getAttribute('data-amount')) || 0;
            const qty = parseFloat(document.getElementById('inventoryQuantity').value) || 0;

            document.getElementById('totalAmount').value = (unitSalesPrice * qty).toFixed(2);
            closeSchedule();
        }

        function validateInventoryInstallmentQuantity() {
            const selectEl = document.getElementById('billCodeSelect');
            if (!selectEl.value) return true;

            const selectedOption = selectEl.options[selectEl.selectedIndex];
            const billType = selectedOption.getAttribute('data-type') || '';

            if (billType !== 'Inventory') return true;

            const qtyInput = document.getElementById('inventoryQuantity');
            const qty = parseFloat(qtyInput.value) || 0;
            const stock = parseFloat(selectedOption.getAttribute('data-stock')) || 0;

            if (qty <= 0) {
                alert('Inventory quantity must be greater than zero.');
                qtyInput.focus();
                return false;
            }

            if (qty > stock) {
                alert(`Not enough inventory quantity balance. Available: ${stock.toFixed(2)}`);
                qtyInput.focus();
                return false;
            }

            return true;
        }

        const modeGaps = {
            'monthly': 1,
            'quarterly': 3,
            'semi_annually': 6,
            'annually': 12
        };

        function addMonthsClamped(startDate, months) {
            const targetFirst = new Date(startDate.getFullYear(), startDate.getMonth() + months, 1);
            const targetLastDay = new Date(targetFirst.getFullYear(), targetFirst.getMonth() + 1, 0).getDate();
            return new Date(
                targetFirst.getFullYear(),
                targetFirst.getMonth(),
                Math.min(startDate.getDate(), targetLastDay)
            );
        }

        function splitAmountIntoCents(totalAmount, count) {
            const totalCents = Math.round(Number(totalAmount) * 100);
            const baseCents = Math.floor(totalCents / count);
            const remainder = totalCents % count;
            return Array.from({ length: count }, (_, index) => (baseCents + (index < remainder ? 1 : 0)) / 100);
        }

        function updatePaymentModes() {
            const term = parseInt(document.getElementById('term').value) || 0;
            const payModeSelect = document.getElementById('payMode');
            const currentVal = payModeSelect.value;
            
            payModeSelect.innerHTML = ''; 
            
            if (term > 0) {
                if (term % 1 === 0) payModeSelect.add(new Option('Monthly', 'monthly'));
                if (term % 3 === 0) payModeSelect.add(new Option('Quarterly', 'quarterly'));
                if (term % 6 === 0) payModeSelect.add(new Option('Semi-Annually', 'semi_annually'));
                if (term % 12 === 0) payModeSelect.add(new Option('Annually', 'annually'));
            } else {
                payModeSelect.add(new Option('Monthly', 'monthly'));
            }

            if (Array.from(payModeSelect.options).some(opt => opt.value === currentVal)) {
                payModeSelect.value = currentVal;
            }
        }
        updatePaymentModes();

        function adjustFlexibleAmounts(currentIndex) {
            const amortType = document.getElementById('amortType').value;
            if (amortType !== 'flexible') return;

            const targetTotal = parseFloat(document.getElementById('totalAmount').value) || 0;
            const inputs = document.querySelectorAll('.editable-amount');
            
            let sumUpToIndex = 0;
            for (let i = 0; i <= currentIndex; i++) {
                sumUpToIndex += parseFloat(inputs[i].value) || 0;
            }

            const remainingBalance = targetTotal - sumUpToIndex;
            const remainingRows = inputs.length - 1 - currentIndex;

            if (remainingRows > 0) {
                let baseAmount = Math.floor((remainingBalance / remainingRows) * 100) / 100;
                let lastAmount = remainingBalance - (baseAmount * (remainingRows - 1));

                for (let i = currentIndex + 1; i < inputs.length; i++) {
                    if (i === inputs.length - 1) {
                        inputs[i].value = lastAmount.toFixed(2);
                    } else {
                        inputs[i].value = baseAmount.toFixed(2);
                    }
                }
            }
            updateRunningTotal();
        }

        function updateRunningTotal() {
            const amortType = document.getElementById('amortType').value;
            const targetTotal = parseFloat(document.getElementById('totalAmount').value) || 0;
            const badge = document.getElementById('scheduleTotalBadge');

            if (amortType === 'flexible') {
                const inputs = document.querySelectorAll('.editable-amount');
                let currentSum = 0;
                inputs.forEach(input => {
                    currentSum += parseFloat(input.value) || 0;
                });
                
                let diff = targetTotal - currentSum;
                if (Math.abs(diff) <= 0.01) {
                    badge.innerHTML = `<span style="color:var(--success);">Sum: ₱ ${currentSum.toFixed(2)} ✓</span>`;
                } else {
                    badge.innerHTML = `<span style="color:var(--danger);">Sum: ₱ ${currentSum.toFixed(2)} (Diff: ₱ ${diff.toFixed(2)})</span>`;
                }
            } else {
                badge.innerHTML = ``; 
            }
        }

        document.getElementById('scheduleForm').addEventListener('submit', function(e) {
            if (!validateInventoryInstallmentQuantity()) {
                e.preventDefault();
                return;
            }

            const amortType = document.getElementById('amortType').value;
            const targetTotal = parseFloat(document.getElementById('totalAmount').value) || 0;

            if (amortType === 'flexible') {
                const inputs = document.querySelectorAll('.editable-amount');
                let currentSum = 0;
                inputs.forEach(input => {
                    currentSum += parseFloat(input.value) || 0;
                });

                if (Math.abs(currentSum - targetTotal) > 0.01) {
                    e.preventDefault(); 
                    alert(`Validation Error: The sum of your flexible schedule (₱ ${currentSum.toFixed(2)}) does not match the Total Amount (₱ ${targetTotal.toFixed(2)}). Please adjust the amounts.`);
                }
            }
        });

        function generateSchedule() {
            if (!validateInventoryInstallmentQuantity()) {
                return;
            }

            const amount = parseFloat(document.getElementById('totalAmount').value);
            const termInMonths = parseInt(document.getElementById('term').value);
            const amortType = document.getElementById('amortType').value;
            const modeVal = document.getElementById('payMode').value;
            const modeGap = modeGaps[modeVal];
            const startDateStr = document.getElementById('startDate').value; 
            
            if (isNaN(amount) || isNaN(termInMonths) || !startDateStr) {
                alert("Please fill in the Total Amount, Term, and Start Date to generate the schedule.");
                return;
            }

            if (!modeGap || termInMonths <= 0 || termInMonths > 120 || termInMonths % modeGap !== 0) {
                alert('The term must be between 1 and 120 months and divisible by the selected payment mode.');
                return;
            }

            const totalPayments = termInMonths / modeGap;
            const tbody = document.getElementById('scheduleBody');
            tbody.innerHTML = "";

            const parts = startDateStr.split('-').map(Number);
            const startDate = new Date(parts[0], parts[1] - 1, parts[2]);
            if (parts.length !== 3 || Number.isNaN(startDate.getTime())) {
                alert('Please select a valid start date.');
                return;
            }

            const isFlexible = (amortType === 'flexible');
            const scheduleAmounts = splitAmountIntoCents(amount, totalPayments);

            document.getElementById('scheduleStatusBadge').innerText = `${totalPayments} Payments • ${amortType.toUpperCase()}`;

            for (let i = 0; i < totalPayments; i++) {
                const currentDate = addMonthsClamped(startDate, i * modeGap);
                
                let ddRaw = String(currentDate.getDate()).padStart(2, '0');
                let mmRaw = String(currentDate.getMonth() + 1).padStart(2, '0');
                let yyyyRaw = currentDate.getFullYear();
                let isoDate = `${yyyyRaw}-${mmRaw}-${ddRaw}`;
                
                let displayDate = `${mmRaw}/${ddRaw}/${yyyyRaw}`;

                let currentAmountHTML;
                const amtToPay = scheduleAmounts[i];

                let hiddenDateInput = `<input type="hidden" name="schedule_dates[]" value="${isoDate}">`;

                if (isFlexible) {
                    currentAmountHTML = `${hiddenDateInput} <input type="number" step="0.01" name="schedule_amounts[]" class="editable-amount" value="${amtToPay.toFixed(2)}" oninput="adjustFlexibleAmounts(${i})" required>`;
                } else {
                    currentAmountHTML = `${hiddenDateInput} <input type="hidden" name="schedule_amounts[]" value="${amtToPay.toFixed(2)}"> <span class="mono" style="font-weight:600;">₱ ${amtToPay.toFixed(2)}</span>`;
                }

                tbody.innerHTML += `
                    <tr>
                        <td style="color:var(--text-secondary); font-weight:600;">${i + 1}</td>
                        <td class="mono">${displayDate}</td>
                        <td>${currentAmountHTML}</td>
                    </tr>
                `;
            }

            updateRunningTotal(); 
            document.getElementById('schedulePreview').classList.add('active');
        }

        function closeSchedule() {
            document.getElementById('schedulePreview').classList.remove('active');
        }

        function openViewModal(installmentId, memberName) {
            document.getElementById('view_member_name').innerText = memberName;
            const tbody = document.getElementById('view_schedule_body');
            tbody.innerHTML = "";

            const schedules = allSchedules[installmentId] || [];

            if (schedules.length === 0) {
                tbody.innerHTML = `<tr><td colspan="6" style="text-align:center; padding: 20px; color:var(--text-muted);">No schedules found.</td></tr>`;
            } else {
                schedules.forEach(sched => {
                    const dueDateObj = new Date(sched.due_date);
                    const formattedDue = `${String(dueDateObj.getMonth() + 1).padStart(2, '0')}/${String(dueDateObj.getDate()).padStart(2, '0')}/${dueDateObj.getFullYear()}`;
                    
                    const paidAtHtml = sched.paid_at 
                        ? `<span class="mono" style="font-size:.75rem;">${new Date(sched.paid_at).toLocaleString()}</span>` 
                        : `<span style="color:var(--text-muted); font-style:italic;">—</span>`;

                    const markedByHtml = sched.marked_by_name 
                        ? `<span style="font-size:.75rem; font-weight:600; color:var(--text-primary); text-transform:capitalize;">${sched.marked_by_name}</span>`
                        : `<span style="color:var(--text-muted); font-style:italic;">—</span>`;

                    const badgeClass = sched.status === 'paid' ? 'badge-success' : 'badge-warning';
                    
                    let actionHtml = '';
                    if (sched.status === 'pending') {
                        actionHtml = `
                            <span class="text-muted" style="font-size:.7rem;">Pay through Collections</span>
                        `;
                    } else {
                        actionHtml = `
                            <span class="text-muted" style="font-size:.7rem;">Reverse through Collections</span>
                        `;
                    }

                    tbody.innerHTML += `
                        <tr>
                            <td class="mono" style="font-weight:600;">${formattedDue}</td>
                            <td class="mono">₱ ${parseFloat(sched.amount).toFixed(2)}</td>
                            <td><span class="badge ${badgeClass}">${sched.status.toUpperCase()}</span></td>
                            <td>${paidAtHtml}</td>
                            <td>${markedByHtml}</td>
                            <td style="text-align: right;">${actionHtml}</td>
                        </tr>
                    `;
                });
            }
            document.getElementById('viewModal').classList.add('active');
        }

        function closeViewModal() {
            document.getElementById('viewModal').classList.remove('active');
        }

        function toggleScheduleStatus() {
            alert('Installment schedule status is maintained by the Collections module.');
        }

        function openStatusModal(id, currentStatus, classId = 0) {
            document.getElementById('status_installment_id').value = id;
            const statusClass = document.getElementById('statusClassId');
            if (statusClass && Number(classId) > 0 && statusClass.querySelector('option[value="' + Number(classId) + '"]')) {
                statusClass.value = String(Number(classId));
                statusClass.dispatchEvent(new Event('change'));
            }
            document.getElementById('edit_status').value = currentStatus === 'cancelled' ? 'cancelled' : 'active';
            document.getElementById('statusModal').classList.add('active');
        }

        function closeStatusModal() {
            document.getElementById('statusModal').classList.remove('active');
        }

        function confirmDelete(id) {
            AppDialog.confirm('Delete this installment contract? All its scheduled payments are removed with it. This cannot be undone.', { title: 'Delete installment contract', ok: 'Delete', tone: 'danger' }).then((yes) => {
                if (!yes) return;
                document.getElementById('delete_installment_id').value = id;
                document.getElementById('deleteForm').submit();
            });
        }
    </script>
</body>
</html>