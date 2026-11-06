<?php
/* AUTH_GUARD_START */
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../process/accessControlHelpers.php';
$__authGuardPdo = getDB();
requirePageAccess($__authGuardPdo, basename(__FILE__, '.php'));
unset($__authGuardPdo);
/* AUTH_GUARD_END */

$pdo = getDB();
require_once __DIR__ . '/../common/displayHelpers.php';
require_once __DIR__ . '/../process/organizationUnitHelpers.php';

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

$q = trim((string)($_GET['q'] ?? ''));
$status = trim((string)($_GET['status'] ?? 'all'));
$dateFrom = trim((string)($_GET['date_from'] ?? date('Y-m-01')));
$dateTo = trim((string)($_GET['date_to'] ?? date('Y-m-d')));
if (!in_array($status, ['all', 'active', 'reversed', 'refunded'], true)) $status = 'all';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) $dateTo = date('Y-m-d');

$transactions = [];
$dataError = null;
try {
    $where = ["COALESCE(pt.business_date, DATE(pt.payment_date)) BETWEEN ? AND ?"];
    $params = [$dateFrom, $dateTo];

    if ($status !== 'all') {
        $where[] = "COALESCE(pt.transaction_status, 'active') = ?";
        $params[] = $status;
    }
    if ($q !== '') {
        $where[] = "(pt.receipt_no LIKE ? OR m.account_number LIKE ? OR CAST(m.pkey AS CHAR) LIKE ? OR CONCAT_WS(' ', m.firstname, m.middlename, m.lastname) LIKE ?)";
        $search = '%' . $q . '%';
        array_push($params, $search, $search, $search, $search);
    }

    $sql = "
        SELECT pt.*,
               m.account_number,
               CONCAT_WS(', ', NULLIF(m.lastname,''), NULLIF(m.firstname,'')) AS member_name,
               COALESCE(NULLIF(cashier.description,''), cashier.name, 'System') AS cashier_name,
               pa.adjustment_type,
               pa.business_date AS adjustment_business_date,
               pa.reason AS adjustment_reason,
               pa.created_at AS adjustment_created_at,
               pa.journal_voucher_id AS adjustment_journal_voucher_id,
               COALESCE(NULLIF(adjuster.description,''), adjuster.name, '') AS adjusted_by_name
        FROM payment_transactions pt
        INNER JOIN water_accounts m ON m.pkey = pt.member_id
        LEFT JOIN operator cashier ON cashier.pkey = pt.cashier_id
        LEFT JOIN payment_adjustments pa
          ON pa.adjustment_id = (
              SELECT MAX(pa2.adjustment_id)
              FROM payment_adjustments pa2
              WHERE pa2.transaction_id = pt.transaction_id
          )
        LEFT JOIN operator adjuster ON adjuster.pkey = pa.created_by
        WHERE " . implode(' AND ', $where) . "
        ORDER BY COALESCE(pt.business_date, DATE(pt.payment_date)) DESC,
                 COALESCE(pt.created_at, pt.payment_date) DESC
        LIMIT 1000
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Collection corrections page error: ' . $e->getMessage());
    $dataError = 'Collection transactions could not be loaded. Confirm that the canonical payment and payment-adjustment tables are installed.';
}

$success = $_SESSION['success_msg'] ?? '';
$error = $_SESSION['error_msg'] ?? '';
unset($_SESSION['success_msg'], $_SESSION['error_msg']);
$csrf = csrfToken();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Collection Refunds and Error Corrections</title>
    <style>
        *{box-sizing:border-box}body{margin:0;background:#f7f8fb;color:#101828;font-family:Arial,Helvetica,sans-serif}.page{max-width:1500px;margin:auto;padding:30px 32px 70px}.head{display:flex;justify-content:space-between;align-items:flex-end;gap:16px;flex-wrap:wrap;margin-bottom:18px}h1{margin:0 0 5px;font-size:25px}.sub{color:#667085;font-size:14px;line-height:1.55;max-width:980px}.card{background:#fff;border:1px solid #e4e7ec;border-radius:12px;margin-bottom:16px;overflow:hidden}.body{padding:17px}.notice{padding:13px 15px;border-radius:9px;margin-bottom:14px;font-size:14px;line-height:1.5}.info{background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8}.success{background:#ecfdf3;border:1px solid #a6f4c5;color:#027a48}.error{background:#fff1f3;border:1px solid #fecdd3;color:#be123c}.filters{display:grid;grid-template-columns:1fr 180px 160px 160px auto;gap:10px;align-items:end}.field label{display:block;margin-bottom:5px;font-size:11px;font-weight:800;text-transform:uppercase;color:#667085}.field input,.field select,.field textarea{width:100%;border:1px solid #d0d5dd;border-radius:7px;padding:10px 11px;background:#fff;font:inherit}.btn{border:0;border-radius:7px;padding:10px 13px;font-weight:800;cursor:pointer;text-decoration:none;display:inline-flex;justify-content:center;align-items:center;background:#101828;color:#fff}.btn.light{background:#fff;color:#101828;border:1px solid #d0d5dd}.btn.warn{background:#b54708}.btn.danger{background:#b42318}.btn.small{padding:7px 10px;font-size:12px}.table-wrap{overflow:auto;max-height:70vh}table{width:100%;min-width:1450px;border-collapse:collapse;font-size:13px}th{position:sticky;top:0;background:#f9fafb;padding:10px;text-align:left;border-bottom:2px solid #e4e7ec;text-transform:uppercase;font-size:10px;color:#667085}td{padding:10px;border-bottom:1px solid #eaecf0;vertical-align:top}.num{text-align:right;font-variant-numeric:tabular-nums}.mono{font-family:Consolas,monospace}.badge{display:inline-block;padding:4px 8px;border-radius:999px;font-size:10px;font-weight:900;text-transform:uppercase;background:#f2f4f7}.badge.active{background:#ecfdf3;color:#027a48}.badge.reversed,.badge.refunded{background:#fff1f3;color:#b42318}.actions{display:flex;gap:6px;flex-wrap:wrap}.muted{color:#667085;font-size:12px;line-height:1.4}.modal-bg{position:fixed;inset:0;background:rgba(15,23,42,.65);display:none;align-items:center;justify-content:center;padding:18px;z-index:9999}.modal-bg.open{display:flex}.modal{width:min(600px,100%);background:#fff;border-radius:12px;overflow:hidden}.modal-head{padding:16px 18px;border-bottom:1px solid #e4e7ec;font-weight:900}.modal-body{padding:18px}.modal-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.full{grid-column:1/-1}.field .water-class-field{margin:0;max-width:none}.field .water-class-hint{display:none}.modal-actions{padding:14px 18px;border-top:1px solid #e4e7ec;background:#f9fafb;display:flex;gap:8px;justify-content:flex-end}@media(max-width:900px){.page{padding:20px 14px}.filters,.modal-grid{grid-template-columns:1fr}.full{grid-column:auto}.btn{width:100%}}
    </style>
</head>
<body>
<?php require_once '../common/navbar.php'; ?>
<div class="main-content"><main class="page">
    <div class="head">
        <div>
            <h1>Collection Refunds and Error Corrections</h1>
            <div class="sub">Every completed collection transaction is shown here. Error Correction reverses a wrong receipt so the cashier can collect it again correctly. Refund returns the allocation to the unpaid balances. Both actions preserve the original receipt and create an opposite journal entry.</div>
        </div>
        <a class="btn light" href="collection_management.php">Back to Collections</a>
    </div>

    <?php if ($success): ?><div class="notice success"><?= e($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="notice error"><?= e($error) ?></div><?php endif; ?>
    <?php if ($dataError): ?><div class="notice error"><?= e($dataError) ?></div><?php endif; ?>
    <div class="notice info"><strong>Accounting control:</strong> the original collection journal is never deleted or edited. The correction posts reversed debit and credit lines in a new journal voucher, restores every affected receivable, and marks the original receipt as reversed or refunded.</div>

    <div class="card"><div class="body">
        <form class="filters" method="get">
            <div class="field"><label>Receipt / Customer / Account</label><input name="q" value="<?= e($q) ?>" placeholder="Search all collection transactions" data-live-search></div>
            <div class="field"><label>Status</label><select name="status"><option value="all">All statuses</option><option value="active" <?= $status==='active'?'selected':'' ?>>Active</option><option value="reversed" <?= $status==='reversed'?'selected':'' ?>>Reversed</option><option value="refunded" <?= $status==='refunded'?'selected':'' ?>>Refunded</option></select></div>
            <div class="field"><label>Date From</label><input type="date" name="date_from" value="<?= e($dateFrom) ?>"></div>
            <div class="field"><label>Date To</label><input type="date" name="date_to" value="<?= e($dateTo) ?>"></div>
            <div style="display:flex;gap:7px"><button class="btn" type="submit">Apply</button><a class="btn light" href="collection_corrections.php">Reset</a></div>
        </form>
    </div></div>

    <div class="card"><div class="table-wrap" id="corrRecords" data-live-results><table>
        <thead><tr><th>Business Date</th><th>Receipt</th><th>Customer</th><th>Type</th><th class="num">Principal</th><th class="num">Penalty</th><th class="num">Compromise</th><th class="num">Total Paid</th><th>Cashier</th><th>Status / Correction Journal</th><th>Action</th></tr></thead>
        <tbody>
        <?php if (!$transactions): ?>
            <tr><td colspan="11" style="padding:28px;text-align:center;color:#667085">No collection transactions match the selected filters.</td></tr>
        <?php else: foreach ($transactions as $tx):
            $txStatus = strtolower((string)($tx['transaction_status'] ?? 'active'));
            $isActive = $txStatus === 'active';
            // a receipt of share capital only has no water journal: its deposits carry their own entries (reversed too)
            $shareCapitalPaid = (float)($tx['share_capital_amount'] ?? 0);
            $hasOriginalJournal = trim((string)($tx['journal_head_id'] ?? '')) !== '' || $shareCapitalPaid > 0;
        ?>
            <tr>
                <td class="mono"><?= e(date('M d, Y', strtotime((string)($tx['business_date'] ?: $tx['payment_date'])))) ?><div class="muted"><?= e(date('h:i A', strtotime((string)$tx['payment_date']))) ?></div></td>
                <td><a href="collection_receipt.php?receipt_no=<?= urlencode((string)$tx['receipt_no']) ?>" target="_blank" class="mono" title="<?= e($tx['receipt_no']) ?>"><?= e(ui_compact_reference($tx['receipt_no'], 20)) ?></a><?php if (trim((string)($tx['journal_head_id'] ?? '')) === '' && $shareCapitalPaid > 0): ?><div class="muted">Original journal: the share capital deposit</div><?php else: ?><div class="muted" title="<?= e($tx['journal_head_id'] ?: 'Missing') ?>">Original journal: <?= e(ui_compact_reference($tx['journal_head_id'] ?: 'Missing', 19)) ?></div><?php endif; ?></td>
                <td><strong><?= e($tx['member_name']) ?></strong><div class="muted">Account: <?= e($tx['account_number'] ?: $tx['member_id']) ?></div></td>
                <td><?= e(['onetime' => 'One-Time', 'sharecap' => 'Share Capital'][(string)$tx['payment_type']] ?? ucwords(str_replace(['_','-'], ' ', (string)$tx['payment_type']))) ?></td>
                <td class="num">₱ <?= number_format((float)$tx['base_amount'], 2) ?><?php if ($shareCapitalPaid > 0): ?><div class="muted">Share capital ₱ <?= number_format($shareCapitalPaid, 2) ?></div><?php endif; ?></td>
                <td class="num">₱ <?= number_format((float)$tx['penalty_amount'], 2) ?></td>
                <td class="num">₱ <?= number_format((float)($tx['compromise_amount'] ?? 0), 2) ?></td>
                <td class="num"><strong>₱ <?= number_format((float)$tx['total_paid'], 2) ?></strong></td>
                <td><?= e($tx['cashier_name']) ?></td>
                <td><span class="badge <?= e($txStatus) ?>"><?= e($txStatus) ?></span><?php if (!$isActive): ?><div class="muted" style="margin-top:5px"><?= e(ucfirst((string)($tx['adjustment_type'] ?? $txStatus))) ?> on <?= e($tx['adjustment_business_date'] ?: '') ?><br>Journal: <span title="<?= e($tx['adjustment_journal_voucher_id'] ?: $tx['reversal_journal_voucher_id'] ?: '—') ?>"><?= e(ui_compact_reference($tx['adjustment_journal_voucher_id'] ?: $tx['reversal_journal_voucher_id'] ?: '—', 19)) ?></span><br><?= e($tx['adjustment_reason'] ?? $tx['reversal_reason'] ?? '') ?></div><?php endif; ?></td>
                <td>
                    <?php if ($isActive && $hasOriginalJournal): ?>
                        <div class="actions">
                            <button type="button" class="btn warn small" onclick="openCorrection(this,'error_correction')" data-receipt="<?= e($tx['receipt_no']) ?>" data-customer="<?= e($tx['member_name']) ?>" data-total="<?= e(number_format((float)$tx['total_paid'],2,'.','')) ?>">Error Correct</button>
                            <button type="button" class="btn danger small" onclick="openCorrection(this,'refund')" data-receipt="<?= e($tx['receipt_no']) ?>" data-customer="<?= e($tx['member_name']) ?>" data-total="<?= e(number_format((float)$tx['total_paid'],2,'.','')) ?>">Refund</button>
                        </div>
                    <?php elseif ($isActive): ?>
                        <div class="muted">Cannot auto-correct because the original journal reference is missing.</div>
                    <?php else: ?>
                        <div class="muted">Correction already completed.</div>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table></div></div>
</main></div>

<div class="modal-bg" id="correctionModal">
    <div class="modal">
        <div class="modal-head" id="modalTitle">Collection Correction</div>
        <form method="post" action="../process/collectionCorrectionProcess.php">
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" id="correctionAction">
                <div class="modal-grid">
                    <div class="field"><label>Receipt Number</label><input name="receipt_no" id="correctionReceipt" readonly></div>
                    <div class="field"><label>Total Collected</label><input id="correctionTotal" readonly></div>
                    <div class="field full"><label>Customer</label><input id="correctionCustomer" readonly></div>
                    <div class="field"><label>Correction / Refund Date</label><input type="date" name="business_date" value="<?= date('Y-m-d') ?>" required></div>
                    <div class="field"><?= render_water_class_field(null, true, 'correctionClassId') ?></div>
                    <div class="field full"><label>Reason</label><textarea name="reason" minlength="5" required placeholder="Explain the cashier error or reason for the refund"></textarea></div>
                    <div class="notice info full" id="modalExplanation" style="margin:0"></div>
                </div>
            </div>
            <div class="modal-actions"><button type="button" class="btn light" onclick="closeCorrection()">Cancel</button><button type="submit" class="btn danger" id="confirmCorrection">Confirm</button></div>
        </form>
    </div>
</div>
<script>
function openCorrection(button, action){
    const receipt=button.dataset.receipt||'';
    const customer=button.dataset.customer||'';
    const total=Number(button.dataset.total||0);
    document.getElementById('correctionAction').value=action;
    document.getElementById('correctionReceipt').value=receipt;
    document.getElementById('correctionCustomer').value=customer;
    document.getElementById('correctionTotal').value='₱ '+total.toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});
    const isRefund=action==='refund';
    document.getElementById('modalTitle').textContent=isRefund?'Refund Collection Transaction':'Error Correct Collection Transaction';
    document.getElementById('confirmCorrection').textContent=isRefund?'Confirm Refund':'Reverse Wrong Receipt';
    document.getElementById('modalExplanation').textContent=isRefund
        ? 'The original receipt will be marked Refunded. All bill allocations will be restored and a reversing journal entry will be created.'
        : 'The original receipt will be marked Reversed. All bill allocations will be restored and a reversing journal entry will be created, after which the cashier can collect the corrected transaction.';
    document.getElementById('correctionModal').classList.add('open');
}
function closeCorrection(){document.getElementById('correctionModal').classList.remove('open');}
document.getElementById('correctionModal').addEventListener('click',function(e){if(e.target===this)closeCorrection();});
</script>
</body></html>
