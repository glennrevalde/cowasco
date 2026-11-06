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
require_once '../common/displayHelpers.php';
$pdo = getDB();

if (!function_exists('e')) {
    function e($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
}

// Fetch the 500 most recent transactions
$transactions = [];
try {
    $sql = "
        SELECT pt.*, 
               CONCAT_WS(', ', NULLIF(m.lastname, ''), NULLIF(m.firstname, '')) AS member_name,
               m.account_number,
               COALESCE(NULLIF(o.description, ''), o.name) AS cashier_name
        FROM payment_transactions pt
        JOIN water_accounts m ON pt.member_id = m.pkey
        LEFT JOIN operator o ON pt.cashier_id = o.pkey
        ORDER BY COALESCE(pt.created_at, pt.payment_date) DESC 
        LIMIT 500
    ";
    $stmt = $pdo->query($sql);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $ex) {
    $db_error = 'Unable to load payment history. Check that the canonical payment migration has been applied.'; error_log('Payment history error: ' . $ex->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../common/fonts.css">
    <title>Payment History Ledger - <?= htmlspecialchars(app_name(), ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --white: #ffffff; --off-white: #f8f9fb; --border: #e8eaed; 
            --text-primary: #0f0f1a; --text-secondary: #6b7280; --text-muted: #9ca3af;
            --info: #2563eb; --info-bg: #eff6ff; 
            --success: #059669; --success-bg: #ecfdf5; 
            --warning: #d97706; --warning-bg: #fffbeb;
            --error: #dc2626; --error-bg: #fef2f2;
            --radius-sm: 8px; --radius: 14px; --shadow-sm: 0 1px 3px rgba(0,0,0,.06);
        }
        html { height: 100%; font-size: 16px; }
        body { font-family: 'Sora', sans-serif; background: var(--off-white); color: var(--text-primary); min-height: 100vh; }
        
        .page { padding: 32px 36px; max-width: 1400px; margin: 0 auto; animation: fadeIn .4s ease; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        
        .page-header { margin-bottom: 24px; }
        .page-title { font-size: 1.5rem; font-weight: 700; letter-spacing: -.03em; }
        .page-subtitle { font-size: .875rem; color: var(--text-secondary); margin-top: 4px; }

        .card { background: var(--white); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow-sm); overflow: hidden; margin-bottom: 24px; }
        .card-head { padding: 18px 22px; border-bottom: 1px solid var(--border); background: #fafafa; display: flex; justify-content: space-between; align-items: center; }
        
        .form-control { width: 300px; padding: 10px 14px; border: 1.5px solid var(--border); border-radius: var(--radius-sm); font-family: 'Sora', sans-serif; font-size: .85rem; outline: none; transition: .2s; }
        .form-control:focus { border-color: var(--info); box-shadow: 0 0 0 3px var(--info-bg); }

        .table-wrap {
            width: 100%;
            max-width: 100%;
            overflow-x: auto;
            overflow-y: auto;
            max-height: 70vh;
            -webkit-overflow-scrolling: touch;
        }
        table {
            width: max-content;
            min-width: 1380px;
            border-collapse: collapse;
            text-align: left;
            font-size: .85rem;
            table-layout: auto;
        }
        thead th { background: var(--off-white); padding: 12px 16px; border-bottom: 2px solid var(--border); color: var(--text-secondary); font-weight: 700; text-transform: uppercase; font-size: .75rem; letter-spacing: .05em; position: sticky; top: 0; z-index: 10; white-space: nowrap; }
        tbody td { padding: 14px 16px; border-bottom: 1px solid var(--border); white-space: nowrap; }
        #historyTable th:nth-child(4), #historyTable td:nth-child(4) { min-width: 230px; }
        #historyTable th:nth-child(11), #historyTable td:nth-child(11) { min-width: 150px; }
        tbody tr:hover { background: #fafbfc; }
        
        .mono { font-family: 'JetBrains Mono', monospace; }
        
        .badge { font-size: .65rem; padding: 4px 8px; border-radius: 20px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; }
        .badge-water { background: #e0f2fe; color: #1e40af; border: 1px solid #bae6fd; }
        .badge-mixed { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
        .badge-active{background:#ecfdf5;color:#047857;border:1px solid #a7f3d0}.badge-reversed,.badge-refunded{background:#fef2f2;color:#b91c1c;border:1px solid #fecaca}.receipt-link{font-family:'JetBrains Mono',monospace;font-weight:800;color:var(--info);text-decoration:none}.receipt-link:hover{text-decoration:underline}.ledger-link{color:var(--info);font-weight:700;text-decoration:none}.ledger-link:hover{text-decoration:underline}.row-menu{min-width:105px}.row-menu summary{list-style:none;cursor:pointer;border:1px solid var(--border);border-radius:7px;padding:6px 9px;background:#fff;font-size:.72rem;font-weight:800;text-align:center}.row-menu summary::-webkit-details-marker{display:none}.row-menu[open] .row-menu-links{display:grid}.row-menu-links{display:none;position:static;min-width:155px;margin-top:5px;background:#fff;border:1px solid var(--border);border-radius:9px;padding:6px;box-shadow:none;gap:2px}.row-menu-links a{padding:7px 8px;border-radius:6px;white-space:nowrap}.row-menu-links a:hover{background:#f3f4f6;text-decoration:none}

        .empty-state { padding: 40px; text-align: center; color: var(--text-muted); }
    </style>
</head>
<body>

<?php require_once '../common/navbar.php'; ?>

<div class="main-content">
    <main class="page">
        <div class="page-header">
            <a href="collection_management.php" style="display:inline-flex; align-items:center; gap:6px; color:var(--text-secondary); text-decoration:none; font-size:.8rem; font-weight:600; margin-bottom:8px;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="14" height="14"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                Back to Collection Management
            </a>
            <h1 class="page-title">Payment History Ledger</h1>
            <p class="page-subtitle">Unified audit log of all point-of-sale transactions and optional manual penalty or compromise charges.</p>
        </div>

        <?php if(isset($db_error)): ?>
            <div style="background: var(--error-bg); border: 1px solid var(--error); color: var(--error); padding: 16px; border-radius: 8px; margin-bottom: 20px; font-weight: 600;">
                Database Error: <?= e($db_error) ?> <br><br>
                <span style="font-size:.8rem;">Make sure you ran the SQL command to create the <code>payment_transactions</code> table!</span>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-head">
                <div style="font-weight:700; font-size: 1rem;">Transaction Log</div>
                <input type="search" id="tableSearch" class="form-control" placeholder="Search by Receipt #, Name, or Acct ID..." data-inc-table="#historyTable tbody" autocomplete="off">
            </div>
            
            <div class="table-wrap">
                <table id="historyTable">
                    <thead>
                        <tr>
                            <th>Recorded At</th>
                            <th>Business Date</th>
                            <th>Receipt No.</th>
                            <th>Account / Member</th>
                            <th>Type</th>
                            <th style="text-align: right;">Base Amount</th>
                            <th style="text-align: right;">Penalty</th>
                            <th style="text-align: right;">Compromise</th>
                            <th style="text-align: right; color:var(--info);">Total Paid</th>
                            <th>Status</th>
                            <th>Cashier</th>
                            <th>Receipts / Ledger</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($transactions)): ?>
                            <tr>
                                <td colspan="12">
                                    <div class="empty-state">No payment transactions found in the ledger yet.</div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($transactions as $t):
                                $paymentType = strtolower((string)($t['payment_type'] ?? 'mixed'));
                                $badgeClass = $paymentType === 'water' ? 'badge-water' : 'badge-mixed';
                                $typeLabels = [
                                    'water' => 'Water Bill',
                                    'installment' => 'Installment',
                                    'onetime' => 'One-Time Bill',
                                    'recurring' => 'Recurring Bill',
                                    'mixed' => 'Mixed Collection',
                                    'sharecap' => 'Share Capital',
                                ];
                                $displayType = $typeLabels[$paymentType] ?? ucwords(str_replace('_', ' ', $paymentType));
                            ?>
                            <tr class="tx-row" data-search="<?= e($t['receipt_no']) ?>">
                                <td class="mono" style="font-size: .8rem; color: var(--text-secondary);">
                                    <?= date('M j, Y h:i A', strtotime($t['created_at'] ?? $t['payment_date'])) ?>
                                </td>
                                <td class="mono" style="font-size:.8rem;"><?= e(isset($t['business_date']) ? date('M j, Y', strtotime($t['business_date'])) : date('M j, Y', strtotime($t['payment_date']))) ?></td>
                                <td>
                                    <a class="receipt-link" href="collection_receipt.php?receipt_no=<?= urlencode((string)$t['receipt_no']) ?>" target="_blank"><span title="<?= e($t['receipt_no']) ?>"><?= e(ui_compact_reference($t['receipt_no'], 20)) ?></span></a>
                                </td>
                                <td>
                                    <strong style="color: var(--text-primary);"><?= e($t['member_name']) ?></strong>
                                    <div class="mono" style="font-size: .7rem; color: var(--text-secondary); margin-top: 2px;">Acct: <?= e($t['account_number'] ?: $t['member_id']) ?></div>
                                </td>
                                <td>
                                    <span class="badge <?= $badgeClass ?>"><?= $displayType ?></span>
                                </td>
                                <td class="mono" style="text-align: right; color: var(--text-secondary);">
                                    ₱ <?= number_format($t['base_amount'], 2) ?>
                                    <?php if ((float)($t['share_capital_amount'] ?? 0) > 0): ?><div style="font-size:.7rem;margin-top:2px">Share capital ₱ <?= number_format((float)$t['share_capital_amount'], 2) ?></div><?php endif; ?>
                                </td>
                                <td class="mono" style="text-align: right; color: <?= $t['penalty_amount'] > 0 ? 'var(--error)' : 'var(--text-secondary)' ?>;">
                                    <?= $t['penalty_amount'] > 0 ? '+ ₱ ' . number_format($t['penalty_amount'], 2) : '—' ?>
                                </td>
                                <td class="mono" style="text-align: right; color: <?= (float)($t['compromise_amount'] ?? 0) > 0 ? 'var(--warning)' : 'var(--text-secondary)' ?>;">
                                    <?= (float)($t['compromise_amount'] ?? 0) > 0 ? '+ ₱ ' . number_format((float)$t['compromise_amount'], 2) : '—' ?>
                                </td>
                                <td class="mono" style="text-align: right; font-weight: 700; color: var(--text-primary);">
                                    ₱ <?= number_format($t['total_paid'], 2) ?>
                                </td>
                                <td><span class="badge badge-<?= e(strtolower((string)($t['transaction_status'] ?? 'active'))) ?>"><?= e($t['transaction_status'] ?? 'active') ?></span></td>
                                <td style="font-size: .8rem; color: var(--text-secondary);"><?= e($t['cashier_name'] ?? 'System') ?></td>
                                <td><details class="row-menu"><summary>Actions</summary><div class="row-menu-links"><a class="ledger-link" href="collection_receipt.php?receipt_no=<?= urlencode((string)$t['receipt_no']) ?>" target="_blank">Water Receipt</a><a class="ledger-link" href="generate_official_receipt.php?receipt_no=<?= urlencode((string)$t['receipt_no']) ?>">Official Receipt</a><a class="ledger-link" href="customer_ledger.php?member_id=<?= urlencode((string)$t['member_id']) ?>" target="_blank">Member Ledger</a><a class="ledger-link" href="collection_corrections.php?q=<?= urlencode((string)$t['receipt_no']) ?>">Correct / Refund</a></div></details></td>
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
    function filterTable() {
        const query = document.getElementById("tableSearch").value.toLowerCase();
        const rows = document.querySelectorAll(".tx-row");

        rows.forEach(row => {
            const textContent = (row.textContent + " " + (row.dataset.search || "")).toLowerCase();
            if (textContent.includes(query)) {
                row.style.display = "";
            } else {
                row.style.display = "none";
            }
        });
    }
</script>
</body>
</html>