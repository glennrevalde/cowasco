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
require_once '../process/organizationUnitHelpers.php';
require_once __DIR__ . '/../common/institutionConfigurationHelpers.php';
$pdo = getDB();
$csrfToken = csrfToken();
/*
 * Penalty and Compromise are always available to the cashier and always start
 * at zero. There is no on/off setting in front of them. The only thing that can
 * stop a non-zero charge is a missing income account, and that is reported as a
 * warning next to the field rather than by locking it.
 */
$manualChargeConfig = [
    'penalty_enabled' => true,
    'compromise_enabled' => true,
    'penalty_account_ready' => false,
    'compromise_account_ready' => false,
];
try {
    $chargeStmt = $pdo->query("SELECT setting_key, setting_value FROM water_billing_settings WHERE setting_key IN ('collection_penalty_account_id','collection_compromise_account_id')");
    $chargeSettings = [];
    foreach ($chargeStmt->fetchAll(PDO::FETCH_ASSOC) as $settingRow) {
        $chargeSettings[(string)$settingRow['setting_key']] = (string)$settingRow['setting_value'];
    }
    $accountCheck = $pdo->prepare("SELECT AccntID FROM chartofaccntstbl WHERE AccntID = ? AND Status = 'A' LIMIT 1");
    foreach (['penalty', 'compromise'] as $chargeName) {
        $accountId = (int)($chargeSettings['collection_' . $chargeName . '_account_id'] ?? 0);
        if ($accountId <= 0) continue;
        $accountCheck->execute([$accountId]);
        $manualChargeConfig[$chargeName . '_account_ready'] = (bool)$accountCheck->fetchColumn();
    }
} catch (Throwable $e) {
    // The fields stay usable; the server reports an unmapped account on submit.
}
$preselectedMember = null;
$preselectedMemberId = (int)($_GET['member_id'] ?? 0);
if ($preselectedMemberId > 0) {
    $preselectStmt = $pdo->prepare("SELECT pkey, CONCAT_WS(', ', NULLIF(lastname, ''), NULLIF(firstname, '')) AS full_name FROM water_accounts WHERE pkey = ? AND status = 'A' LIMIT 1");
    $preselectStmt->execute([$preselectedMemberId]);
    $preselectedMember = $preselectStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if (!function_exists('e')) {
    function e($value): string { return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../common/fonts.css">
    <title>Unified Collections - <?= htmlspecialchars(app_name(), ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --white:#fff; --off-white:#f8f9fb; --border:#e8eaed; --border-focus:#1a1a2e;
            --text-primary:#0f0f1a; --text-secondary:#6b7280; --text-muted:#9ca3af;
            --accent:#1a1a2e; --success:#059669; --success-bg:#ecfdf5; --error:#dc2626; --error-bg:#fef2f2;
            --info:#2563eb; --info-bg:#eff6ff; --warning:#d97706; --warning-bg:#fffbeb;
            --radius-sm:8px; --radius:14px; --shadow-sm:0 1px 3px rgba(0,0,0,.06); --shadow-lg:0 10px 40px rgba(0,0,0,.12);
        }
        body { font-family:'Sora',sans-serif; background:var(--off-white); color:var(--text-primary); min-height:100vh; }
        .page { padding:32px 36px; max-width:1450px; margin:0 auto; animation:fadeIn .35s ease; }
        @keyframes fadeIn { from{opacity:0; transform:translateY(10px);} to{opacity:1; transform:translateY(0);} }
        .page-header { display:flex; justify-content:space-between; align-items:flex-end; margin-bottom:24px; gap:16px; flex-wrap:wrap; }
        .page-title { font-size:1.55rem; font-weight:800; letter-spacing:-.03em; }
        .page-subtitle { font-size:.875rem; color:var(--text-secondary); margin-top:4px; line-height:1.5; }
        .cashier-grid { display:grid; grid-template-columns:1fr 430px; gap:24px; align-items:start; }
        @media (max-width:1024px){ .cashier-grid{grid-template-columns:1fr;} }
        .card { background:var(--white); border:1px solid var(--border); border-radius:var(--radius); box-shadow:var(--shadow-sm); overflow:visible; margin-bottom:20px; }
        .card-head { padding:18px 22px; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; background:#fafafa; border-radius:var(--radius) var(--radius) 0 0; gap:12px; }
        .card-title { font-size:.95rem; font-weight:800; display:flex; align-items:center; gap:8px; color:var(--info); }
        .card-body { padding:22px; }
        .search-wrap { position:relative; margin-bottom:24px; z-index:50; }
        .form-control { width:100%; padding:12px 16px; border:1.5px solid var(--border); border-radius:var(--radius-sm); font-family:'Sora',sans-serif; font-size:.9rem; transition:border-color .2s; outline:none; }
        .form-control:focus { border-color:var(--info); box-shadow:0 0 0 3px var(--info-bg); }
        .search-dropdown { position:absolute; top:100%; left:0; right:0; background:var(--white); border:1.5px solid var(--border); border-top:none; border-radius:0 0 var(--radius-sm) var(--radius-sm); box-shadow:var(--shadow-lg); max-height:320px; overflow-y:auto; display:none; }
        .search-dropdown.open { display:block; }
        .search-result { display:flex; flex-direction:column; padding:12px 16px; cursor:pointer; border-bottom:1px solid var(--border); transition:background .1s; }
        .search-result:hover { background:var(--info-bg); }
        .search-res-name { font-weight:800; font-size:.9rem; }
        .search-res-meta { font-size:.75rem; color:var(--text-secondary); margin-top:2px; }
        .bill-section-title { font-size:.78rem; font-weight:800; text-transform:uppercase; color:var(--text-muted); letter-spacing:.05em; margin:18px 0 10px; border-bottom:2px solid var(--border); padding-bottom:6px; display:flex; justify-content:space-between; align-items:center; }
        .bill-section-title:first-child { margin-top:0; }
        .bill-card { display:flex; align-items:center; justify-content:space-between; padding:15px; border:1.5px solid var(--border); border-radius:var(--radius-sm); margin-bottom:10px; cursor:pointer; transition:all .2s; gap:12px; }
        .bill-card:hover { border-color:var(--info); background:var(--info-bg); }
        .bill-card.selected { border-color:var(--success); background:var(--success-bg); }
        .bill-left { display:flex; gap:12px; align-items:flex-start; min-width:0; }
        .chk-wrap { margin-top:2px; }
        .bill-meta-title { font-weight:800; font-size:.88rem; }
        .bill-meta-sub { font-size:.75rem; color:var(--text-secondary); margin-top:4px; display:flex; flex-wrap:wrap; gap:7px; }
        .bill-amount { font-family:'JetBrains Mono',monospace; font-size:.92rem; font-weight:800; white-space:nowrap; }
        .badge { display:inline-flex; align-items:center; padding:3px 8px; border-radius:20px; font-size:.64rem; font-weight:800; text-transform:uppercase; }
        .badge-water { background:var(--info-bg); color:var(--info); }
        .badge-installment { background:#f3e8ff; color:#7c3aed; }
        .badge-onetime { background:var(--warning-bg); color:var(--warning); }
        .badge-recurring { background:#ecfeff; color:#0891b2; }
        .badge-sharecap { background:#ecfdf5; color:#047857; }
        .share-capital-box { margin-top:16px; padding:12px 14px 4px; border:1px solid #bbf7d0; border-radius:8px; background:#f6fef9; }
        .share-capital-box .cart-row:last-child { border-bottom:0; }
        .badge-late { background:var(--error-bg); color:var(--error); border:1px solid #fecaca; }
        .cart-row { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; padding:8px 0; font-size:.85rem; border-bottom:1px dashed var(--border); }
        .cart-row strong { font-family:'JetBrains Mono',monospace; }
        .cart-total { display:flex; justify-content:space-between; align-items:center; padding:18px 0 10px; border-top:2px solid var(--border); margin-top:14px; font-weight:800; }
        .cart-total-amount { font-size:1.35rem; color:var(--accent); font-family:'JetBrains Mono',monospace; }
        .penalty-input { width:120px; padding:7px 10px; border:1.5px solid var(--border); border-radius:6px; text-align:right; font-family:'JetBrains Mono',monospace; font-weight:700; }
        .penalty-input:disabled, .penalty-input[readonly] { background:var(--off-white); color:var(--text-primary); cursor:not-allowed; }
        .other-fees-box { margin-top:16px; padding:12px 14px 4px; border:1px solid var(--border); border-radius:8px; background:#fafafa; }
        .other-fees-title { display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:4px; color:var(--text-primary); font-size:.76rem; font-weight:900; letter-spacing:.04em; text-transform:uppercase; }
        .other-fees-note { margin-bottom:6px; color:var(--text-muted); font-size:.69rem; line-height:1.45; }
        .other-fees-box .cart-row:last-child { border-bottom:0; }
        .allocation-input { width:118px; padding:8px 10px; border:1.5px solid var(--border); border-radius:7px; text-align:right; font-family:'JetBrains Mono',monospace; font-weight:800; }
        .allocation-input:focus { border-color:var(--info); outline:none; box-shadow:0 0 0 3px var(--info-bg); }
        .payment-input-wrap { position:relative; margin-top:14px; }
        .payment-input-wrap span { position:absolute; left:16px; top:50%; transform:translateY(-50%); color:var(--text-muted); font-weight:800; }
        .payment-input { width:100%; padding:14px 16px 14px 42px; border:2px solid var(--border); border-radius:var(--radius-sm); font-family:'JetBrains Mono',monospace; font-size:1.25rem; font-weight:800; outline:none; }
        .change-row { display:flex; justify-content:space-between; align-items:center; margin:12px 0 20px; font-size:.88rem; color:var(--text-secondary); }
        .change-amount { font-family:'JetBrains Mono',monospace; font-weight:800; }
        .btn { width:100%; border:none; border-radius:var(--radius-sm); padding:13px 16px; font-family:'Sora',sans-serif; font-weight:800; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:8px; transition:.2s; }
        .btn-success { background:var(--success); color:#fff; }
        .btn:disabled { opacity:.5; cursor:not-allowed; }
        .spinner { display:none; width:16px; height:16px; border:2px solid rgba(255,255,255,.3); border-top-color:#fff; border-radius:50%; animation:spin .8s linear infinite; }
        @keyframes spin { to { transform:rotate(360deg); } }
        .alert-box { display:none; padding:12px 16px; border-radius:var(--radius-sm); margin-bottom:18px; font-weight:700; font-size:.85rem; }
        .alert-success { display:block; background:var(--success-bg); color:var(--success); border:1px solid #a7f3d0; }
        .alert-error { display:block; background:var(--error-bg); color:var(--error); border:1px solid #fecaca; }
        .alert-info { display:block; background:var(--info-bg); color:var(--info); border:1px solid #bfdbfe; }

        .receipt-modal {
            position: fixed;
            inset: 0;
            z-index: 99999;
            background: rgba(15, 23, 42, .62);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 18px;
        }

        .receipt-modal.open {
            display: flex;
        }

        .receipt-dialog {
            width: min(860px, 100%);
            max-height: 92vh;
            background: #fff;
            border-radius: 18px;
            overflow: hidden;
            box-shadow: 0 24px 80px rgba(0,0,0,.28);
            display: flex;
            flex-direction: column;
        }

        .receipt-dialog-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            padding: 16px 20px;
            border-bottom: 1px solid var(--border);
            background: #fafafa;
        }

        .receipt-dialog-title {
            font-size: .98rem;
            font-weight: 900;
        }

        .receipt-dialog-sub {
            font-size: .78rem;
            color: var(--text-secondary);
            margin-top: 3px;
        }

        .receipt-dialog-body {
            overflow: auto;
            padding: 20px;
        }

        .mini-receipt {
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 20px;
            background: #fff;
        }

        .mini-receipt-top {
            display: flex;
            justify-content: space-between;
            gap: 14px;
            border-bottom: 2px solid var(--text-primary);
            padding-bottom: 14px;
            margin-bottom: 16px;
        }

        .mini-company {
            font-weight: 900;
            font-size: 1.1rem;
        }

        .mini-muted {
            color: var(--text-secondary);
            font-size: .78rem;
            line-height: 1.5;
        }

        .mini-receipt-no {
            font-family: 'JetBrains Mono', monospace;
            font-weight: 900;
            font-size: .86rem;
            background: var(--off-white);
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 6px 10px;
            display: inline-flex;
            white-space: nowrap;
        }

        .mini-meta {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
            margin: 16px 0;
        }

        .mini-box {
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 10px 12px;
        }

        .mini-label {
            font-size: .65rem;
            color: var(--text-secondary);
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .06em;
            margin-bottom: 4px;
        }

        .mini-value {
            font-weight: 800;
            font-size: .85rem;
            overflow-wrap: anywhere;
        }

        .mini-table {
            width: 100%;
            border-collapse: collapse;
            font-size: .82rem;
            margin-top: 12px;
        }

        .mini-table th {
            text-align: left;
            color: var(--text-secondary);
            font-size: .65rem;
            text-transform: uppercase;
            letter-spacing: .06em;
            padding: 8px 6px;
            border-bottom: 1.5px solid var(--text-primary);
        }

        .mini-table td {
            padding: 9px 6px;
            border-bottom: 1px solid var(--border);
            vertical-align: top;
        }

        .mini-amount {
            text-align: right;
            font-family: 'JetBrains Mono', monospace;
            white-space: nowrap;
            font-weight: 800;
        }

        .mini-section-title {
            margin-top: 14px;
            padding: 8px 10px;
            border: 1px solid var(--border);
            border-bottom: 0;
            border-radius: 10px 10px 0 0;
            background: var(--off-white);
            color: var(--text-primary);
            font-size: .68rem;
            font-weight: 900;
            letter-spacing: .06em;
            text-transform: uppercase;
        }

        .mini-other-fees {
            border: 1px solid var(--border);
            border-radius: 0 0 10px 10px;
            overflow: hidden;
        }

        .mini-total-box {
            margin-left: auto;
            margin-top: 14px;
            width: min(340px, 100%);
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
        }

        .mini-total-row {
            display: flex;
            justify-content: space-between;
            gap: 14px;
            padding: 9px 12px;
            border-bottom: 1px solid var(--border);
            font-size: .84rem;
        }

        .mini-total-row:last-child {
            border-bottom: 0;
        }

        .mini-total-row.grand {
            background: var(--accent);
            color: #fff;
            font-weight: 900;
        }

        .receipt-actions {
            padding: 14px 20px;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            flex-wrap: wrap;
            background: #fafafa;
        }

        .receipt-btn {
            border: 0;
            border-radius: 10px;
            padding: 10px 14px;
            font-family: 'Sora', sans-serif;
            font-weight: 900;
            cursor: pointer;
            background: var(--accent);
            color: #fff;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .receipt-btn.light {
            background: #fff;
            color: var(--accent);
            border: 1px solid var(--border);
        }

        @media (max-width: 720px) {
            .receipt-dialog {
                max-height: 96vh;
            }

            .receipt-dialog-body {
                padding: 14px;
            }

            .mini-receipt {
                padding: 14px;
            }

            .mini-receipt-top {
                flex-direction: column;
            }

            .mini-meta {
                grid-template-columns: 1fr;
            }

            .receipt-actions {
                align-items: stretch;
            }

            .receipt-btn {
                flex: 1 1 100%;
            }
        }

    </style>
</head>
<body>
<?php require_once '../common/navbar.php'; ?>
<div class="main-content">
<main class="page">
    <div class="page-header">
        <div>
            <a href="collection_management.php" style="display:inline-flex; align-items:center; gap:6px; color:var(--text-secondary); text-decoration:none; font-size:.8rem; font-weight:700; margin-bottom:8px;">← Back to Collection Management</a>
            <h1 class="page-title">Unified Collection Cashiering</h1>
            <p class="page-subtitle">Process water bills, installment schedules, one-time fees, recurring bills and fixed share capital contributions in one collection page. A bill collected posts Debit Cash, Credit Accounts Receivable with the member subsidiary ledger; a share capital contribution is a deposit to the member's share capital account.</p>
        </div>
    </div>

    <div id="mainAlert" class="alert-box"></div>

    <div class="cashier-grid">
        <div>
            <div class="search-wrap" id="searchWrap">
                <input type="text" id="memberSearch" class="form-control" placeholder="Search member name or account ID..." autocomplete="off" data-inc-list="#searchDropdown" data-inc-item=".search-result">
                <div class="search-dropdown" id="searchDropdown"></div>
            </div>

            <div class="card" id="billsContainer" style="display:none;">
                <div class="card-head">
                    <div class="card-title">Receivables for <span id="dispMemberName">—</span></div>
                    <span style="font-size:.85rem; color:var(--text-secondary); font-weight:700;" id="dispMemberAcct">—</span>
                    <input type="hidden" id="currentMemberId">
                </div>
                <div class="card-body">
                    <div class="bill-section-title">
                        Available Receivables
                        <label style="cursor:pointer; display:flex; align-items:center; gap:6px; color:var(--text-primary); text-transform:none;">
                            <input type="checkbox" id="chkAllBills" onchange="toggleAll(this.checked)"> Select All
                        </label>
                    </div>
                    <div id="receivablesList"></div>
                </div>
            </div>
        </div>

        <div>
            <div class="card" style="position:sticky; top:24px;">
                <div class="card-head"><div class="card-title" style="color:var(--text-primary);">Payment Receipt</div></div>
                <div class="card-body">
                    <div style="margin-bottom:16px;">
                        <label style="font-size:.75rem; font-weight:800; color:var(--text-secondary); text-transform:uppercase; letter-spacing:.04em; display:block; margin-bottom:8px;">Date of Transaction</label>
                        <input type="date" id="transactionDate" class="form-control" value="<?= date('Y-m-d') ?>" onchange="requestQuote()" required>
                        <div style="font-size:.72rem; color:var(--text-muted); margin-top:6px;">This is the controlled business date. The actual creation timestamp is stored separately. Future dates and excessive backdating are rejected by the server.</div>
                    </div>

                    
                    <div style="margin-bottom:16px;">
                        <?= render_water_class_field(null, true, 'classId') ?>
                    </div>

                    <div id="cartSummary"><div style="text-align:center; color:var(--text-muted); font-size:.85rem; padding:20px;">No bills selected.</div></div>

                    <div class="share-capital-box" id="shareCapitalBox" aria-labelledby="shareCapitalTitle" hidden>
                        <div class="other-fees-title" id="shareCapitalTitle"><span>Share Capital</span></div>
                        <div class="other-fees-note">Fixed Share Capital Billing: deposited to the member's share capital account when paid.</div>
                        <div id="selectedShareCapital"></div>
                    </div>

                    <div class="other-fees-box" id="otherFees" aria-labelledby="otherFeesTitle">
                        <div class="other-fees-title" id="otherFeesTitle"><span>Other Fees</span></div>
                        <div id="selectedOtherFees"></div>
                        <div id="manualChargeStageHint" style="font-size:.72rem;color:var(--text-muted);padding:4px 0 10px;line-height:1.5;">Penalty and Compromise start at zero. Leave them at 0.00 unless the cashier is charging one.</div>
                        <div class="cart-row" id="penaltyRow">
                            <span>
                                <label for="penaltyAmount" style="display:flex;align-items:center;gap:8px;font-weight:800;color:var(--error);">Penalty</label>
                                <div style="font-size:.72rem;color:var(--text-muted);margin-top:3px;">Cashier-entered. Zero means no penalty is charged on this receipt.</div>
                                <?php if (!$manualChargeConfig['penalty_account_ready']): ?><div style="font-size:.72rem;color:var(--warning);margin-top:3px;font-weight:700;">Map a Penalty income account in Settings &rarr; Collection Policy before charging one.</div><?php endif; ?>
                            </span>
                            <input type="number" id="penaltyAmount" class="penalty-input" value="0.00" placeholder="0.00" min="0" step="0.01" aria-label="Penalty amount">
                        </div>
                        <div class="cart-row" id="compromiseRow">
                            <span>
                                <label for="compromiseAmount" style="display:flex;align-items:center;gap:8px;font-weight:800;color:var(--warning);">Compromise</label>
                                <div style="font-size:.72rem;color:var(--text-muted);margin-top:3px;">Cashier-entered. Zero means no compromise is charged on this receipt.</div>
                                <?php if (!$manualChargeConfig['compromise_account_ready']): ?><div style="font-size:.72rem;color:var(--warning);margin-top:3px;font-weight:700;">Map a Compromise income account in Settings &rarr; Collection Policy before charging one.</div><?php endif; ?>
                            </span>
                            <input type="number" id="compromiseAmount" class="penalty-input" value="0.00" placeholder="0.00" min="0" step="0.01" aria-label="Compromise amount">
                        </div>
                    </div>

                    <div class="cart-total"><span>Total Due</span><span class="cart-total-amount" id="dispTotalDue">₱ 0.00</span></div>
                    <div class="payment-input-wrap"><span>₱</span><input type="number" id="tenderedAmount" class="payment-input" placeholder="0.00" step="0.01" disabled oninput="calculateChange()"></div>
                    <div class="change-row"><span>Change</span><span class="change-amount" id="dispChange">₱ 0.00</span></div>
                    <button class="btn btn-success" id="btnProcess" disabled onclick="processPayment()"><span class="spinner" id="btnSpinner"></span><span id="btnText">Confirm Payment</span></button>
                </div>
            </div>
        </div>
    </div>
</main>
</div>

<div class="receipt-modal" id="receiptModal" aria-hidden="true">
    <div class="receipt-dialog">
        <div class="receipt-dialog-head">
            <div>
                <div class="receipt-dialog-title">Payment Receipt Ready</div>
                <div class="receipt-dialog-sub">Review the receipt below or open the full printable version.</div>
            </div>
            <button type="button" class="receipt-btn light" onclick="closeReceiptModal()">Close</button>
        </div>
        <div class="receipt-dialog-body">
            <div id="receiptPreview"></div>
        </div>
        <div class="receipt-actions">
            <button type="button" class="receipt-btn light" onclick="closeReceiptModal()">Continue Collection</button>
            <button type="button" class="receipt-btn light" onclick="openReceiptFullPage(false)">Open Receipt</button>
            <button type="button" class="receipt-btn" onclick="openReceiptFullPage(true)">Print Receipt</button>
        </div>
    </div>
</div>

<script>
const WSH_ISSUER_NAME = <?= json_encode(institution_issuer($pdo)['name'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
const CSRF_TOKEN = <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const MANUAL_CHARGE_CONFIG = <?= json_encode($manualChargeConfig, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const PRESELECTED_MEMBER = <?= json_encode($preselectedMember, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
let searchTimer = null;
let quoteTimer = null;
let selectedItems = new Map();
let serverQuote = null;
let lastReceipt = null;
let idempotencyKey = newPaymentKey();

const fmtPeso = n => '₱ ' + Number(n || 0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
const e = str => String(str ?? '').replace(/[&<>'"]/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[m]));
const typeLabel = t => ({water:'Water', installment:'Installment', onetime:'One-Time', recurring:'Recurring', sharecap:'Share Capital'}[t] || t);
const typeBadge = t => ({water:'badge-water', installment:'badge-installment', onetime:'badge-onetime', recurring:'badge-recurring', sharecap:'badge-sharecap'}[t] || 'badge-water');

function newPaymentKey() {
    if (window.crypto?.randomUUID) return window.crypto.randomUUID().replaceAll('-', '');
    return Date.now().toString(36) + Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2);
}
function showAlert(type, msg) {
    const box = document.getElementById('mainAlert');
    box.className = `alert-box alert-${type}`;
    box.textContent = msg;
    setTimeout(() => { if (box.textContent === msg) box.className = 'alert-box'; }, 8000);
}

const searchInput = document.getElementById('memberSearch');
const searchDropdown = document.getElementById('searchDropdown');
let memberSearchSeq = 0;   // a late answer to an older query is dropped
searchInput.addEventListener('input', function() {
    clearTimeout(searchTimer);
    const q = this.value.trim();
    const seq = ++memberSearchSeq;
    if (q.length < 1) { searchDropdown.classList.remove('open'); return; }
    searchTimer = setTimeout(async () => {
        try {
            const r = await fetch(`../process/searchAllReceivables.php?q=${encodeURIComponent(q)}`);
            const data = await r.json();
            if (seq !== memberSearchSeq) return;
            searchDropdown.innerHTML = Array.isArray(data) && data.length
                ? data.map(m => `<button type="button" class="search-result" style="width:100%;text-align:left;border:0;background:#fff" data-member='${e(JSON.stringify(m))}'><span class="search-res-name">${e(m.full_name)}</span><span class="search-res-meta">Account ID: ${e(m.pkey)} | Open Items: ${e(m.unpaid_count)}</span></button>`).join('')
                : `<div style="padding:16px;font-size:.85rem;color:var(--text-muted);text-align:center;">No members found with unpaid receivables.</div>`;
            searchDropdown.querySelectorAll('[data-member]').forEach(btn => btn.addEventListener('click', () => selectMember(JSON.parse(btn.dataset.member))));
            searchDropdown.classList.add('open');
        } catch { if (seq === memberSearchSeq) showAlert('error', 'Unable to search members.'); }
    }, 160);
});
document.addEventListener('click', ev => { if (!searchInput.contains(ev.target) && !searchDropdown.contains(ev.target)) searchDropdown.classList.remove('open'); });

async function selectMember(memberData) {
    searchDropdown.classList.remove('open');
    searchInput.value = memberData.full_name || '';
    document.getElementById('currentMemberId').value = memberData.pkey;
    document.getElementById('dispMemberName').textContent = memberData.full_name || 'Member';
    document.getElementById('dispMemberAcct').textContent = `Account ID: ${memberData.pkey}`;
    document.getElementById('billsContainer').style.display = 'block';
    selectedItems.clear(); serverQuote = null; idempotencyKey = newPaymentKey(); updateCart();
    const list = document.getElementById('receivablesList');
    list.innerHTML = '<div style="text-align:center;padding:20px;color:var(--text-muted);">Loading receivables…</div>';
    try {
        const r = await fetch(`../process/getAllReceivables.php?member_id=${encodeURIComponent(memberData.pkey)}`);
        const items = await r.json();
        if (!Array.isArray(items) || !items.length) {
            list.innerHTML = '<div style="color:var(--success);font-size:.85rem;font-weight:700;padding:16px;text-align:center;background:var(--success-bg);border-radius:8px;">No outstanding receivables found.</div>';
            return;
        }
        list.innerHTML = items.map(item => {
            const key = `${item.db_type}:${item.db_id}`;
            const balance = Number(item.balance ?? item.amount ?? 0);
            return `<div class="bill-card" id="card_${e(key)}">
                <div class="bill-left">
                    <div class="chk-wrap"><input type="checkbox" class="chk-bill" data-key="${e(key)}" aria-label="Select ${e(item.description)}"></div>
                    <div><div class="bill-meta-title">${e(item.description)} <span class="badge ${typeBadge(item.db_type)}">${typeLabel(item.db_type)}</span> ${item.is_late ? '<span class="badge badge-late">Late</span>' : ''}</div>
                    <div class="bill-meta-sub">${item.db_type === 'sharecap' ? `<span>Posted: ${e(item.billing_date || '—')}</span><span>${item.sc_due_date ? 'Due: ' + e(item.sc_due_date) : 'No due date'}</span><span>Member: ${e(item.member_name || '')}</span>` : `<span>Due: ${e(item.due_date || '—')}</span>`}<span>Balance: ${fmtPeso(balance)}</span></div></div>
                </div>
                <div style="text-align:right"><label style="font-size:.66rem;color:var(--text-muted);display:block;margin-bottom:4px">PAYMENT</label><input class="allocation-input" type="number" min="0.01" step="0.01" max="${balance.toFixed(2)}" value="${balance.toFixed(2)}" data-key="${e(key)}" disabled></div>
            </div>`;
        }).join('');
        items.forEach(item => {
            const key = `${item.db_type}:${item.db_id}`;
            const model = {...item, key, balance:Number(item.balance ?? item.amount ?? 0), payment_amount:Number(item.balance ?? item.amount ?? 0)};
            const check = list.querySelector(`input.chk-bill[data-key="${CSS.escape(key)}"]`);
            const amount = list.querySelector(`input.allocation-input[data-key="${CSS.escape(key)}"]`);
            check.addEventListener('change', () => toggleItem(model, check.checked, amount));
            amount.addEventListener('input', () => { model.payment_amount = Number(amount.value || 0); if (selectedItems.has(key)) selectedItems.set(key, {...model}); updateCart(); requestQuote(); });
        });
    } catch { list.innerHTML = '<div class="alert-error" style="padding:14px">Unable to load receivables.</div>'; }
}
function toggleItem(model, checked, amountInput) {
    amountInput.disabled = !checked;
    document.getElementById(`card_${model.key}`)?.classList.toggle('selected', checked);
    if (checked) { model.payment_amount = Number(amountInput.value || model.balance); selectedItems.set(model.key, {...model}); }
    else selectedItems.delete(model.key);
    updateCart(); requestQuote();
}
function toggleAll(checked) { document.querySelectorAll('.chk-bill').forEach(cb => { if (cb.checked !== checked) { cb.checked = checked; cb.dispatchEvent(new Event('change')); } }); }
function isOtherFeeType(type) {
    return ['installment', 'onetime', 'recurring'].includes(String(type || '').toLowerCase());
}
function selectedItemRow(item) {
    return `<div class="cart-row"><span>${e(item.description)}<small style="display:block;color:var(--text-muted)">${typeLabel(item.db_type)}, balance ${fmtPeso(item.balance)}</small></span><strong>${fmtPeso(item.payment_amount)}</strong></div>`;
}
function updateCart() {
    const selected = Array.from(selectedItems.values());
    const waterItems = selected.filter(item => String(item.db_type || '').toLowerCase() === 'water');
    const otherFeeItems = selected.filter(item => isOtherFeeType(item.db_type));
    const shareItems = selected.filter(item => String(item.db_type || '').toLowerCase() === 'sharecap');
    const shareBox = document.getElementById('shareCapitalBox');
    if (shareBox) {
        shareBox.hidden = !shareItems.length;
        document.getElementById('selectedShareCapital').innerHTML = shareItems.map(selectedItemRow).join('');
    }

    document.getElementById('cartSummary').innerHTML = waterItems.length
        ? waterItems.map(selectedItemRow).join('')
        : '<div style="text-align:center;color:var(--text-muted);font-size:.85rem;padding:20px;">No water bills selected.</div>';

    const selectedOtherFees = document.getElementById('selectedOtherFees');
    if (selectedOtherFees) {
        selectedOtherFees.innerHTML = otherFeeItems.map(selectedItemRow).join('');
    }

    if (!selectedItems.size) { serverQuote=null; document.getElementById('dispTotalDue').textContent=fmtPeso(0); document.getElementById('tenderedAmount').value=''; updateManualChargeStageHint(null); }
    document.getElementById('tenderedAmount').disabled = !selectedItems.size;
    calculateChange();
}
function requestQuote() {
    clearTimeout(quoteTimer);
    if (!selectedItems.size) return updateCart();
    if (typeof manualFeesAreReady === 'function' && !manualFeesAreReady()) {
        serverQuote = null;
        calculateChange();
        return;
    }
    quoteTimer = setTimeout(loadQuote, 220);
}
async function loadQuote() {
    const memberId = Number(document.getElementById('currentMemberId').value || 0);
    const date = document.getElementById('transactionDate').value;
    if (!memberId || !date) return;
    try {
        const r = await fetch('../process/getPaymentQuote.php', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf_token:CSRF_TOKEN,member_id:memberId,transaction_date:date,apply_penalty:manualFeeIsSelected('penalty'),penalty_amount:manualFeeAmount('penalty'),apply_compromise:manualFeeIsSelected('compromise'),compromise_amount:manualFeeAmount('compromise'),items:Array.from(selectedItems.values()).map(i=>({db_type:i.db_type,db_id:i.db_id,payment_amount:i.payment_amount}))})});
        const data = await r.json();
        if (!data.success) throw new Error(data.error || 'Unable to calculate payment.');
        serverQuote = data.quote;
        updateManualChargeStageHint(serverQuote);
        document.getElementById('dispTotalDue').textContent = fmtPeso(serverQuote.total_amount || 0);
        calculateChange();
    } catch (err) { serverQuote=null; document.getElementById('btnProcess').disabled=true; showAlert('error', err.message); }
}
function calculateChange() {
    const total = Number(serverQuote?.total_amount || 0);
    const tendered = Number(document.getElementById('tenderedAmount').value || 0);
    const change = tendered >= total && total > 0 ? tendered-total : 0;
    document.getElementById('dispChange').textContent = fmtPeso(change);
    document.getElementById('dispChange').style.color = change >= 0 && tendered >= total && total > 0 ? 'var(--success)' : 'var(--text-muted)';
    document.getElementById('btnProcess').disabled = !(serverQuote && total > 0 && tendered >= total);
}
document.getElementById('tenderedAmount').addEventListener('input', calculateChange);
/*
 * Penalty and Compromise are plain cashier fields. They are always open, they
 * always start at 0.00, and a zero simply means the charge was not applied.
 * There is no eligibility stage and no checkbox to tick.
 */
function manualFeeElements(name) {
    return { amount: document.getElementById(name + 'Amount') };
}
function manualFeeAmount(name) {
    const {amount} = manualFeeElements(name);
    if (!amount) return '0';
    const value = Number(amount.value || 0);
    return Number.isFinite(value) && value > 0 ? String(value) : '0';
}
function manualFeeIsSelected(name) {
    return Number(manualFeeAmount(name)) > 0;
}
function clearManualFee(name) {
    const {amount} = manualFeeElements(name);
    if (amount) amount.value = '0.00';
}
function resetManualFees() {
    ['penalty', 'compromise'].forEach(clearManualFee);
}
function manualFeesAreReady() {
    return ['penalty', 'compromise'].every(name => {
        const {amount} = manualFeeElements(name);
        if (!amount) return true;
        const value = Number(amount.value || 0);
        return Number.isFinite(value) && value >= 0;
    });
}

function updateManualChargeStageHint(quote) {
    const hint = document.getElementById('manualChargeStageHint');
    if (!hint) return;

    const charged = ['penalty', 'compromise'].filter(manualFeeIsSelected);
    if (charged.length) {
        hint.textContent = 'Charging ' + charged.map(n => n === 'penalty' ? 'Penalty' : 'Compromise').join(' and ')
            + '. The amount is attached to the oldest receivable on this receipt.';
        hint.style.color = 'var(--warning)';
        return;
    }

    if (quote && quote.has_overdue_receivable) {
        hint.textContent = 'This selection includes an overdue receivable. Enter a Penalty or Compromise amount if one applies, otherwise leave both at 0.00.';
        hint.style.color = 'var(--text-secondary)';
        return;
    }

    hint.textContent = 'Penalty and Compromise start at zero. Leave them at 0.00 unless the cashier is charging one.';
    hint.style.color = 'var(--text-muted)';
}

['penalty', 'compromise'].forEach(name => {
    const {amount} = manualFeeElements(name);
    if (!amount) return;
    amount.addEventListener('input', () => {
        updateManualChargeStageHint(serverQuote);
        if (manualFeesAreReady()) requestQuote();
        else { serverQuote = null; calculateChange(); }
    });
    amount.addEventListener('blur', () => {
        const value = Number(amount.value || 0);
        amount.value = (Number.isFinite(value) && value > 0 ? value : 0).toFixed(2);
    });
});


function receiptTypeText(type) { return ({water:'Water Bill',installment:'Installment',onetime:'One-Time Bill',recurring:'Recurring Bill',sharecap:'Share Capital',mixed:'Mixed Collection',penalty:'Penalty',compromise:'Compromise'}[type] || type); }
function renderReceiptPreview(receipt) {
    const status = String(receipt.transaction_status || 'active').toUpperCase();
    const items = Array.isArray(receipt.items) ? receipt.items : [];
    const waterItems = items.filter(item => String(item.source_type || '').toLowerCase() === 'water');
    const otherFeeItems = Array.isArray(receipt.other_fee_items)
        ? receipt.other_fee_items
        : items.filter(item => isOtherFeeType(item.source_type));

    const waterRows = waterItems.map(item => `<tr><td><strong>${e(item.description || receiptTypeText(item.source_type))}</strong><div class="mini-muted">${e(item.source_label || receiptTypeText(item.source_type))} #${e(item.source_id || '')}</div></td><td>${e(item.due_date || '—')}</td><td class="mini-amount">${fmtPeso(item.principal_amount ?? item.amount ?? 0)}<div class="mini-muted">Balance ${fmtPeso(item.balance_after || 0)}</div></td></tr>`).join('');
    const waterSection = waterRows
        ? `<div class="mini-section-title">Water Bills</div><table class="mini-table" style="margin-top:0"><thead><tr><th>Description</th><th>Due</th><th style="text-align:right">Allocation</th></tr></thead><tbody>${waterRows}</tbody></table>`
        : '';

    const normalizedOtherFees = otherFeeItems.map(item => ({
        label: item.description || item.source_label || receiptTypeText(item.source_type),
        meta: item.due_date ? `Due ${item.due_date}` : (item.source_id ? `${receiptTypeText(item.source_type)} #${item.source_id}` : ''),
        amount: Number(item.principal_amount ?? item.amount ?? 0)
    })).filter(item => item.amount > 0);

    if (!Array.isArray(receipt.other_fee_items)) {
        if (Number(receipt.penalty_amount || 0) > 0) normalizedOtherFees.push({label:'Penalty', meta:'Manual charge', amount:Number(receipt.penalty_amount)});
        if (Number(receipt.compromise_amount || 0) > 0) normalizedOtherFees.push({label:'Compromise', meta:'Manual charge', amount:Number(receipt.compromise_amount)});
    }

    const otherFeeRows = normalizedOtherFees.map(item => `<div class="mini-total-row"><span><strong>${e(item.label)}</strong>${item.meta ? `<small class="mini-muted" style="display:block">${e(item.meta)}</small>` : ''}</span><strong>${fmtPeso(item.amount)}</strong></div>`).join('');
    const scOnReceipt = Number(receipt.share_capital_amount || 0);
    const feesTitle = scOnReceipt <= 0 ? 'Other Fees' : (normalizedOtherFees.reduce((t, i) => t + i.amount, 0) - scOnReceipt > 0.004 ? 'Other Fees and Share Capital' : 'Share Capital');
    const otherFeesSection = otherFeeRows
        ? `<div class="mini-section-title">${feesTitle}</div><div class="mini-other-fees">${otherFeeRows}</div>`
        : '';

    const waterPaid = waterItems.reduce((total, item) => total + Number(item.principal_amount ?? item.amount ?? 0), 0);
    const otherFeesTotal = Number(receipt.other_fees_total ?? normalizedOtherFees.reduce((total, item) => total + item.amount, 0));
    // the share capital collected (Fixed Share Capital Billing) is shown apart from the fees
    const shareCapitalPaid = Number(receipt.share_capital_amount || 0);
    const feesOnly = Math.max(0, Math.round((otherFeesTotal - shareCapitalPaid) * 100) / 100);
    const summaryRows = [
        waterPaid > 0 ? `<div class="mini-total-row"><span>Water Bills</span><strong>${fmtPeso(waterPaid)}</strong></div>` : '',
        feesOnly > 0 ? `<div class="mini-total-row"><span>Other Fees</span><strong>${fmtPeso(feesOnly)}</strong></div>` : '',
        shareCapitalPaid > 0 ? `<div class="mini-total-row"><span>Share Capital</span><strong>${fmtPeso(shareCapitalPaid)}</strong></div>` : ''
    ].join('');

    return `<div class="mini-receipt"><div class="mini-receipt-top"><div><div class="mini-company">${e(WSH_ISSUER_NAME)}</div><div class="mini-muted">Official collection receipt (${e(status)})</div></div><div style="text-align:right"><div style="font-weight:900;letter-spacing:.08em">RECEIPT</div><div class="mini-receipt-no">${e(receipt.receipt_no)}</div></div></div><div class="mini-meta"><div class="mini-box"><div class="mini-label">Received From</div><div class="mini-value">${e(receipt.member_name || '')}</div><div class="mini-muted">Acct: ${e(receipt.account_number || receipt.member_id || '')}</div></div><div class="mini-box"><div class="mini-label">Business Date / Cashier</div><div class="mini-value">${e(receipt.business_date_display || receipt.payment_date_display || receipt.business_date || '')}</div><div class="mini-muted">${e(receipt.cashier_name || 'System')}</div></div></div>${waterSection}${otherFeesSection}<div class="mini-total-box">${summaryRows}<div class="mini-total-row grand"><span>Total Paid</span><strong>${fmtPeso(receipt.total_paid || 0)}</strong></div><div class="mini-total-row"><span>Tendered</span><strong>${fmtPeso(receipt.tendered_amount || 0)}</strong></div><div class="mini-total-row"><span>Change</span><strong>${fmtPeso(receipt.change_amount || 0)}</strong></div></div></div>`;
}
function showReceiptModal(receipt){if(!receipt)return;lastReceipt=receipt;document.getElementById('receiptPreview').innerHTML=renderReceiptPreview(receipt);const m=document.getElementById('receiptModal');m.classList.add('open');m.setAttribute('aria-hidden','false');}
function closeReceiptModal(){const m=document.getElementById('receiptModal');m.classList.remove('open');m.setAttribute('aria-hidden','true');}
function openReceiptFullPage(autoPrint){if(!lastReceipt?.receipt_no)return showAlert('error','No receipt is available.');window.open(`collection_receipt.php?receipt_no=${encodeURIComponent(lastReceipt.receipt_no)}${autoPrint?'&autoprint=1':''}`,'_blank','noopener,noreferrer');}

async function processPayment() {
    if (!serverQuote) return showAlert('error','Wait for the payment total.');
    const classBox=document.getElementById('classId');
    if (classBox && !classBox.value) { showAlert('error','Choose the class of the collection: it is required, and the receipt\'s journal entry carries it.'); classBox.focus(); return; }
    const btn=document.getElementById('btnProcess'), text=document.getElementById('btnText'), spinner=document.getElementById('btnSpinner');
    btn.disabled=true;text.textContent='Processing…';spinner.style.display='inline-block';
    try {
        const input={csrf_token:CSRF_TOKEN,idempotency_key:idempotencyKey,member_id:Number(document.getElementById('currentMemberId').value),transaction_date:document.getElementById('transactionDate').value,tendered:Number(document.getElementById('tenderedAmount').value||0),class_id:Number(document.getElementById('classId')?.value||0),apply_penalty:manualFeeIsSelected('penalty'),penalty_amount:manualFeeAmount('penalty'),apply_compromise:manualFeeIsSelected('compromise'),compromise_amount:manualFeeAmount('compromise'),items:Array.from(selectedItems.values()).map(i=>({db_type:i.db_type,db_id:i.db_id,payment_amount:i.payment_amount}))};
        const r=await fetch('../process/processUnifiedPayment.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(input)});
        const res=await r.json(); if(!res.success)throw new Error(res.error||'Payment failed.');
        showAlert('success',`${res.duplicate?'Duplicate submission safely recovered':'Payment successful'}. Receipt: ${res.receipt_no}`);
        if(res.receipt)showReceiptModal(res.receipt);
        selectedItems.clear();serverQuote=null;idempotencyKey=newPaymentKey();document.getElementById('tenderedAmount').value='';resetManualFees();document.getElementById('chkAllBills').checked=false;updateCart();
        const member={pkey:document.getElementById('currentMemberId').value,full_name:document.getElementById('dispMemberName').textContent}; await selectMember(member);
    } catch(err){showAlert('error',err.message||'Payment failed.');}
    finally{text.textContent='Confirm Payment';spinner.style.display='none';calculateChange();}
}
if (PRESELECTED_MEMBER && PRESELECTED_MEMBER.pkey) {
    selectMember(PRESELECTED_MEMBER);
}
</script>
</body>
</html>
