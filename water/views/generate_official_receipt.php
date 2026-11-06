<?php
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../process/accessControlHelpers.php';
require_once __DIR__ . '/../common/collectionReceiptHelpers.php';
require_once __DIR__ . '/../common/institutionConfigurationHelpers.php';
require_once __DIR__ . '/../common/displayHelpers.php';

$pdo = getDB();
requirePageAccess($pdo, basename(__FILE__, '.php'));

if (!function_exists('or_e')) {
    function or_e($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('or_money')) {
    function or_money($value, bool $blankWhenZero = false): string
    {
        $amount = round((float)($value ?? 0), 2);
        if ($blankWhenZero && abs($amount) < 0.005) {
            return '';
        }
        return number_format($amount, 2, '.', ',');
    }
}

$receiptNo = trim((string)($_GET['receipt_no'] ?? ''));
$receipt = null;
$loadError = '';
$transactions = [];

try {
    $transactionStmt = $pdo->query(
        "SELECT
            pt.receipt_no,
            pt.payment_date,
            pt.business_date,
            pt.total_paid,
            pt.payment_type,
            pt.transaction_status,
            m.account_number,
            CONCAT_WS(', ', NULLIF(m.lastname, ''), NULLIF(m.firstname, '')) AS member_name
         FROM payment_transactions pt
         JOIN water_accounts m ON m.pkey = pt.member_id
         WHERE pt.transaction_status = 'active'
         ORDER BY COALESCE(pt.created_at, pt.payment_date) DESC, pt.transaction_id DESC
         LIMIT 500"
    );
    $transactions = $transactionStmt->fetchAll(PDO::FETCH_ASSOC);

    if ($receiptNo !== '') {
        $receipt = cr_fetch_receipt($pdo, $receiptNo);
        if (!$receipt) {
            $loadError = 'The selected payment transaction could not be found.';
        }
    }
} catch (Throwable $error) {
    error_log('[generate_official_receipt] ' . $error->getMessage());
    $loadError = 'Official receipt data could not be loaded. Verify the payment tables and try again.';
}

$services = is_array($receipt['official_receipt_services'] ?? null)
    ? $receipt['official_receipt_services']
    : [];
$minimumServiceRows = 11;
$serviceRowCount = max($minimumServiceRows, count($services));
$receiptStatus = strtolower((string)($receipt['transaction_status'] ?? 'active'));
$isActiveReceipt = $receiptStatus === 'active';
// the paid bill-code amounts and the total of the print are changed only by a user whose role has the special
// permission "Water Billing: can change the paid bill-code amounts ... on an official receipt" (administrators always)
$canEditAmounts = (bool)Access::special('water_receipt_amounts');
$amountLock = $canEditAmounts ? '' : ' readonly tabindex="-1" title="Only users allowed to change official receipt amounts can edit this."';
$orIssuer = institution_issuer($pdo);
$paymentDateIso = $receipt
    ? date('Y-m-d', strtotime((string)($receipt['business_date'] ?? $receipt['payment_date'] ?? 'now')))
    : '';
$paymentDate = $paymentDateIso !== '' ? date('m/d/Y', strtotime($paymentDateIso)) : '';
$totalPaid = (float)($receipt['total_paid'] ?? 0);
$customerName = strtoupper(trim((string)($receipt['member_name'] ?? '')));
// Printed (the whole official receipt on plain paper) or Pre-Printed (only the entries, on the cooperative's own
// official receipts): the company's choice (Company, Security & Closing Date > Water Billing); ?type= prints the
// other way once (version 46)
$orType = WaterReceiptLayout::type('official');
if (isset($_GET['type']) && isset(WaterReceiptLayout::TYPES[(string)$_GET['type']])) {
    $orType = (string)$_GET['type'];
}
$orPre = $orType === 'preprinted';
$orLayout = $orPre ? WaterReceiptLayout::get('official') : null;
$orLines = [];
foreach ($services as $service) {
    $orLines[] = ['description' => (string)$service['description'], 'amount' => number_format((float)$service['amount'], 2, '.', '')];
}
$orTotal = number_format($totalPaid, 2, '.', '');
$orVals = ['date' => $paymentDateIso, 'name' => $customerName, 'account' => (string)($receipt['account_number'] ?? ''), 'number' => $receiptNo,
    'total_sales' => $orTotal, 'total_due' => $orTotal, 'total_amount_due' => $orTotal, 'exempt_sales' => $orTotal, 'cash_mark' => '✓',
    'cashier' => (string)($receipt['cashier_name'] ?? '')];
$orInch = fn(int $tw) => rtrim(rtrim(number_format($tw / 1440, 2, '.', ''), '0'), '.');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Generate Official Receipt - <?= htmlspecialchars(app_name(), ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="../common/fonts.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        :root {
            --white: #fff; --off-white: #f8f9fb; --border: #e5e7eb;
            --text: #111827; --muted: #6b7280; --accent: #1a1a2e;
            --danger: #b91c1c; --danger-bg: #fef2f2; --success: #047857;
        }
        body { margin: 0; min-height: 100vh; background: var(--off-white); color: var(--text); font-family: 'Sora', sans-serif; }
        .page { max-width: 1480px; margin: 0 auto; padding: 28px 28px 52px; }
        .page-header { margin-bottom: 18px; padding-bottom: 16px; border-bottom: 1px solid var(--border); }
        .back-link { display: inline-flex; align-items: center; gap: 6px; margin-bottom: 8px; color: var(--muted); font-size: .8rem; font-weight: 600; text-decoration: none; }
        .page-title { margin: 0; font-size: 1.45rem; letter-spacing: -.025em; }
        .page-subtitle { max-width: 880px; margin: 6px 0 0; color: var(--muted); font-size: .84rem; line-height: 1.55; }
        .card { overflow: hidden; margin-bottom: 18px; border: 1px solid var(--border); border-radius: 8px; background: var(--white); }
        .card-head { display: flex; align-items: center; justify-content: space-between; gap: 14px; padding: 15px 18px; border-bottom: 1px solid var(--border); background: #fafafa; }
        .card-title { font-size: .94rem; font-weight: 700; }
        .card-copy { margin-top: 3px; color: var(--muted); font-size: .74rem; line-height: 1.45; }
        .search-input { width: min(360px, 100%); padding: 9px 11px; border: 1px solid #d1d5db; border-radius: 6px; font: 500 .78rem 'Sora', sans-serif; outline: none; }
        .search-input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(26,26,46,.08); }
        .table-wrap { max-height: 310px; overflow: auto; }
        table.transaction-table { width: 100%; border-collapse: collapse; font-size: .78rem; }
        .transaction-table th { position: sticky; top: 0; z-index: 1; padding: 10px 12px; background: #f8fafc; border-bottom: 1px solid var(--border); color: #4b5563; font-size: .67rem; letter-spacing: .04em; text-align: left; text-transform: uppercase; white-space: nowrap; }
        .transaction-table td { padding: 10px 12px; border-bottom: 1px solid #eef0f3; vertical-align: middle; }
        .transaction-table tr:last-child td { border-bottom: 0; }
        .transaction-table tbody tr:hover { background: #fafafa; }
        .mono { font-family: 'JetBrains Mono', monospace; }
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; min-height: 34px; padding: 8px 13px; border: 0; border-radius: 5px; font: 650 .73rem 'Sora', sans-serif; cursor: pointer; text-decoration: none; }
        .btn-primary { background: var(--accent); color: #fff; }
        .btn-light { border: 1px solid var(--border); background: #fff; color: var(--text); }
        .btn:disabled { cursor: not-allowed; opacity: .45; }
        .empty { padding: 34px; color: var(--muted); text-align: center; font-size: .8rem; }
        .alert { margin-bottom: 18px; padding: 12px 14px; border: 1px solid #fecaca; border-radius: 7px; background: var(--danger-bg); color: var(--danger); font-size: .8rem; font-weight: 600; }

        .generator-grid { display: grid; grid-template-columns: minmax(280px, 360px) minmax(720px, 1fr); gap: 18px; align-items: start; }
        .editor { position: sticky; top: 18px; }
        .editor-body { display: grid; grid-template-columns: minmax(0, 1fr); gap: 12px; padding: 16px 18px 18px; }
        .editor-body > * { min-width: 0; }
        .field { display: grid; gap: 5px; }
        .field label { color: #374151; font-size: .69rem; font-weight: 700; letter-spacing: .02em; text-transform: uppercase; }
        .field input, .field select { width: 100%; padding: 9px 10px; border: 1px solid #d1d5db; border-radius: 5px; color: var(--text); font: 500 .77rem 'Sora', sans-serif; outline: none; }
        .field input:focus, .field select:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(26,26,46,.08); }
        .editor-actions { display: flex; flex-wrap: wrap; gap: 8px; padding-top: 4px; }
        .editor-actions .btn { flex: 1 1 auto; }
        .editor-actions .btn-primary { flex-basis: 100%; }
        .field input[readonly] { background: #f3f4f6; color: #4b5563; cursor: not-allowed; box-shadow: none; }
        .lock-note { padding: 8px 10px; border-radius: 5px; background: #f8fafc; border: 1px solid #e2e8f0; color: #475569; font-size: .7rem; line-height: 1.45; }
        .status-note { padding: 10px 11px; border-radius: 5px; background: #fff7ed; color: #9a3412; font-size: .72rem; font-weight: 650; line-height: 1.45; }

        .amount-list { display: grid; gap: 8px; }
        .amount-row { display: grid; grid-template-columns: minmax(0, 1fr) 128px; gap: 8px; align-items: center; }
        .amount-label { overflow: hidden; color: #374151; font-size: .75rem; font-weight: 650; text-overflow: ellipsis; white-space: nowrap; }
        .amount-input-wrap { position: relative; }
        .amount-input-wrap::before { content: '₱'; position: absolute; top: 50%; left: 10px; color: #6b7280; font-size: .75rem; transform: translateY(-50%); pointer-events: none; }
        .amount-input-wrap input { padding-left: 25px; text-align: right; }

        .receipt-stage { overflow: auto; padding: 18px; border: 1px solid var(--border); border-radius: 8px; background: #e5e7eb; }
        .official-receipt {
            width: 9in;
            min-height: 5.5in;
            margin: 0 auto;
            padding: .18in;
            background: #fff;
            color: #111;
            font-family: Arial, Helvetica, sans-serif;
            box-shadow: 0 8px 28px rgba(15,23,42,.16);
        }
        .or-frame { display: grid; grid-template-columns: minmax(0, 1fr) 1.76in; min-height: 5.14in; border: 2px solid #111; }
        .or-main { min-width: 0; border-right: 1.5px solid #111; }
        .or-side { min-width: 0; }
        .or-header { position: relative; min-height: .98in; padding: .15in .16in .08in; border-bottom: 1.5px solid #111; text-align: center; }
        .or-org { font-size: 13px; font-weight: 800; letter-spacing: -.15px; }
        .or-address, .or-contact, .or-tin { margin-top: 2px; font-size: 8.4px; font-weight: 700; }
        .or-date { position: absolute; right: .16in; bottom: .05in; display: flex; align-items: flex-end; gap: 4px; font-size: 8px; }
        .or-date-line { min-width: 1.05in; padding-bottom: 1px; border-bottom: 1px solid #111; font: 700 9px 'Courier New', monospace; }
        .or-side-top { position: relative; height: 1.29in; padding: .1in .08in .06in; border-bottom: 1px solid #111; }
        .or-validity { font-size: 7px; font-style: italic; font-weight: 700; line-height: 1.2; text-align: left; }
        .or-number { margin-top: .12in; font: 700 10px 'Courier New', monospace; letter-spacing: 2px; text-align: center; }
        .or-no-label { font-size: 9px; letter-spacing: 0; }
        .or-title { position: absolute; right: .08in; bottom: .06in; left: .08in; padding: 4px 4px 3px; border: 1.5px solid #111; border-radius: 4px; background: #fff; font-size: 11px; font-weight: 800; text-align: center; }

        .or-customer-grid { display: grid; grid-template-columns: 1fr; min-height: .37in; border-bottom: 1px solid #111; }
        .or-field { display: flex; min-width: 0; padding: 4px 6px; border-right: 1px solid #111; font-size: 8px; }
        .or-field:last-child { border-right: 0; }
        .or-field-label { flex: 0 0 auto; margin-right: 5px; font-weight: 800; }
        .or-field-value { min-width: 0; overflow: hidden; font: 700 8.3px 'Courier New', monospace; text-overflow: ellipsis; white-space: nowrap; }
        .or-secondary-grid { display: grid; grid-template-columns: 1.05fr .58fr .82fr; min-height: .34in; border-bottom: 1px solid #111; }

        .service-heading { display: grid; grid-template-columns: 1fr 1.13in; min-height: .34in; border-bottom: 1px solid #111; }
        .service-heading > div { display: flex; align-items: center; justify-content: center; padding: 3px 6px; font-size: 8px; font-weight: 800; text-align: center; }
        .service-heading > div:first-child { border-right: 1px solid #111; }
        .service-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .service-table td { height: .225in; padding: 1px 7px; border-bottom: 1px solid #555; font-size: 8.5px; line-height: 1.05; }
        .service-table td:first-child { width: calc(100% - 1.13in); border-right: 1px solid #111; text-align: center; }
        .service-table td:last-child { width: 1.13in; font: 700 8.5px 'Courier New', monospace; text-align: right; }
        .service-table.dense td { height: .19in; font-size: 7.6px; }
        .or-footer-left { display: grid; grid-template-columns: 1fr 1.9in; min-height: .46in; }
        .or-printer-space { border-right: 1px solid #111; }
        .or-system-ref { display: flex; flex-direction: column; justify-content: center; padding: 3px 7px; font-size: 6.7px; line-height: 1.25; }
        .or-system-ref strong { font: 700 7px 'Courier New', monospace; }

        .side-total { display: grid; grid-template-columns: 1fr; min-height: .33in; padding: 3px 7px; border-bottom: 1px solid #111; }
        .side-label { font-size: 7px; font-weight: 800; }
        .side-value { align-self: end; font: 700 9px 'Courier New', monospace; text-align: right; }
        .side-payment { display: flex; align-items: center; justify-content: center; min-height: .42in; padding: 4px; border-bottom: 1px solid #111; font-size: 7.5px; font-weight: 700; text-align: center; }
        .payment-box { display: inline-block; width: 9px; height: 9px; margin: 0 2px 0 5px; border: 1px solid #111; vertical-align: -1px; }
        .payment-box.checked::after { content: '✓'; display: block; margin-top: -4px; margin-left: 1px; font-size: 11px; font-weight: 900; }
        .cashier-box { display: flex; min-height: .58in; flex-direction: column; justify-content: flex-end; padding: 5px 8px 4px; border-bottom: 1px solid #111; text-align: center; }
        .cashier-name { overflow: hidden; padding-bottom: 2px; border-bottom: 1px solid #111; font: 700 8px 'Courier New', monospace; text-overflow: ellipsis; white-space: nowrap; }
        .cashier-label { padding-top: 3px; font-size: 7px; font-weight: 800; }
        .invalid-tax { display: flex; min-height: .54in; align-items: center; justify-content: center; padding: 4px 8px; font-size: 7.4px; font-weight: 800; line-height: 1.2; text-align: center; }
        .void-watermark { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; color: rgba(185,28,28,.16); font-size: 64px; font-weight: 900; letter-spacing: 8px; transform: rotate(-18deg); pointer-events: none; }
        .receipt-wrapper { position: relative; }

        @media (max-width: 1120px) {
            .generator-grid { grid-template-columns: 1fr; }
            .editor { position: static; }
        }
        @media (max-width: 760px) {
            .page { padding: 22px 14px 40px; }
            .card-head { align-items: stretch; flex-direction: column; }
            .search-input { width: 100%; }
            .receipt-stage { padding: 10px; }
        }
        @media print {
            @page { size: 9in 5.5in landscape; margin: 0; }
            html, body { width: 9in; height: 5.5in; background: #fff !important; }
            body * { visibility: hidden !important; }
            .receipt-wrapper, .receipt-wrapper * { visibility: visible !important; }
            .receipt-wrapper { position: absolute; top: 0; left: 0; width: 9in; height: 5.5in; margin: 0; }
            .official-receipt { width: 9in; min-height: 5.5in; margin: 0; padding: .18in; box-shadow: none; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
            .void-watermark { display: none; }
        }
    </style>
    <?php if ($orPre): $ops = $orLayout['setup']; ?>
    <style>
        /* the official receipt printed on a pre-printed form: only the entries, at the size of the form */
        <?= WaterReceiptLayout::css($orLayout) ?>
        .receipt-wrapper.is-preprinted { display: block; max-width: 100%; overflow: hidden; }
        .receipt-wrapper.is-preprinted .wrl-fit { margin: 0 auto; transform-origin: top left; }
        .receipt-stage .pre-note { margin: 0 0 10px; color: var(--muted); font-size: .74rem; }
        @media print {
            html, body { width: <?= $orInch($ops['page_width']) ?>in; height: <?= $orInch($ops['page_height']) ?>in; }
            .receipt-wrapper.is-preprinted { position: absolute; top: 0; left: 0; width: <?= $orInch($ops['page_width']) ?>in; height: <?= $orInch($ops['page_height']) ?>in; overflow: hidden; }
            .receipt-wrapper.is-preprinted .wrl-fit { transform: none !important; width: auto !important; height: auto !important; margin: 0; }
            .receipt-wrapper.is-preprinted .wrl-page { box-shadow: none; outline: 0; }
            .receipt-wrapper.is-preprinted .wrl-area { outline: 0; }
            .receipt-stage .pre-note { display: none; }
        }
    </style>
    <?php endif; ?>
</head>
<body>
<?php require_once __DIR__ . '/../common/navbar.php'; ?>
<div class="main-content">
    <main class="page">
        <header class="page-header">
            <a href="reports_management.php" class="back-link">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="14" height="14" aria-hidden="true"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                Back to Reports Management
            </a>
            <h1 class="page-title">Generate Official Receipt</h1>
            <p class="page-subtitle">Select a completed payment. The official receipt uses only the payment date, customer name, total amount, and the amount paid for each bill code.</p>
        </header>

        <?php if ($loadError !== ''): ?>
            <div class="alert"><?= or_e($loadError) ?></div>
        <?php endif; ?>

        <section class="card" aria-label="Completed payment selection">
            <div class="card-head">
                <div>
                    <div class="card-title">Completed Payments</div>
                    <div class="card-copy">Only active payment transactions are listed. Search by receipt number, member name, or account number.</div>
                </div>
                <input id="receiptSearch" class="search-input" type="search" placeholder="Search completed payments..." autocomplete="off">
            </div>
            <div class="table-wrap">
                <table class="transaction-table" id="receiptTable">
                    <thead>
                        <tr>
                            <th>Payment Date</th>
                            <th>Receipt No.</th>
                            <th>Account / Member</th>
                            <th>Type</th>
                            <th style="text-align:right">Paid</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$transactions): ?>
                        <tr><td colspan="6" class="empty">No active completed payments are available.</td></tr>
                    <?php else: ?>
                        <?php foreach ($transactions as $transaction): ?>
                            <tr class="receipt-row">
                                <td class="mono"><?= or_e(date('M d, Y h:i A', strtotime((string)$transaction['payment_date']))) ?></td>
                                <td class="mono" title="<?= or_e($transaction['receipt_no']) ?>"><strong><?= or_e(ui_compact_reference($transaction['receipt_no'], 20)) ?></strong></td>
                                <td>
                                    <strong><?= or_e($transaction['member_name']) ?></strong><br>
                                    <span class="mono" style="color:var(--muted);font-size:.69rem"><?= or_e($transaction['account_number'] ?: 'No account number') ?></span>
                                </td>
                                <td><?= or_e(ucwords(str_replace('_', ' ', (string)$transaction['payment_type']))) ?></td>
                                <td class="mono" style="text-align:right">₱ <?= or_e(or_money($transaction['total_paid'])) ?></td>
                                <td style="text-align:right"><a class="btn btn-light" href="generate_official_receipt.php?receipt_no=<?= urlencode((string)$transaction['receipt_no']) ?>">Generate</a></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <?php if ($receipt): ?>
            <div class="generator-grid">
                <section class="card editor" aria-label="Official receipt print fields">
                    <div class="card-head">
                        <div>
                            <div class="card-title">Official Receipt Values</div>
                            <div class="card-copy">Only these values are placed on the generated receipt. Changes affect this print preview only and do not modify the saved payment.</div>
                        </div>
                    </div>
                    <div class="editor-body">
                        <?php if (!$isActiveReceipt): ?>
                            <div class="status-note">This transaction is <?= or_e(strtoupper($receiptStatus)) ?>. Printing is disabled because it is no longer an active payment.</div>
                        <?php endif; ?>
                        <div class="field">
                            <label for="editPaymentDate">Payment Date</label>
                            <input id="editPaymentDate" type="date" value="<?= or_e($paymentDateIso) ?>">
                        </div>
                        <div class="field">
                            <label for="editCustomerName">Customer Name</label>
                            <input id="editCustomerName" type="text" value="<?= or_e($customerName) ?>" maxlength="100">
                        </div>
                        <div class="field">
                            <label>Paid Bill-Code Amounts</label>
                            <?php if (!$canEditAmounts): ?>
                                <div class="lock-note">The amounts are printed as paid. Changing them needs the permission "can change the paid bill-code amounts on an official receipt" (User Role Configuration).</div>
                            <?php endif; ?>
                            <div class="amount-list">
                                <?php if (!$services): ?>
                                    <div class="status-note">No paid bill-code amounts were found for this transaction.</div>
                                <?php else: ?>
                                    <?php foreach ($services as $serviceIndex => $service): ?>
                                        <div class="amount-row">
                                            <div class="amount-label" title="<?= or_e($service['description']) ?>"><?= or_e($service['description']) ?></div>
                                            <div class="amount-input-wrap">
                                                <input
                                                    class="service-amount-input"
                                                    type="number"
                                                    min="0"
                                                    step="0.01"
                                                    value="<?= or_e(number_format((float)$service['amount'], 2, '.', '')) ?>"
                                                    data-preview-id="previewServiceAmount<?= (int)$serviceIndex ?>"
                                                    data-line="<?= (int)$serviceIndex ?>"
                                                    aria-label="<?= or_e($service['description']) ?> amount"<?= $amountLock ?>>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="field">
                            <label for="editTotalAmount">Total Amount</label>
                            <input id="editTotalAmount" type="number" min="0" step="0.01" value="<?= or_e(number_format($totalPaid, 2, '.', '')) ?>"<?= $amountLock ?>>
                        </div>
                        <div class="editor-actions">
                            <button type="button" class="btn btn-primary" id="printOfficialReceipt" <?= !$isActiveReceipt ? 'disabled' : '' ?>>Print Official Receipt</button>
                            <a class="btn btn-light" href="payment_history.php">Payment History</a>
                            <a class="btn btn-light" href="generate_official_receipt.php?receipt_no=<?= urlencode($receiptNo) ?>&amp;type=<?= $orPre ? 'printed' : 'preprinted' ?>"><?= $orPre ? 'Print on plain paper' : 'Print on a pre-printed form' ?></a>
                        </div>
                    </div>
                </section>

                <section class="receipt-stage" aria-label="Official receipt preview">
                    <?php if ($orPre): ?>
                    <p class="pre-note">Pre-printed official receipt: only the entries print, each where the form has room for it (Receipt Layout Setup). Print at Actual Size / 100%.</p>
                    <div class="receipt-wrapper is-preprinted">
                        <?php if (!$isActiveReceipt): ?><div class="void-watermark"><?= or_e(strtoupper($receiptStatus)) ?></div><?php endif; ?>
                        <div class="wrl-fit" id="orPreFit"><?= WaterReceiptLayout::render('official', $orLayout, $orVals, $orLines, 'OTHER SERVICES') ?></div>
                    </div>
                    <?php else: ?>
                    <div class="receipt-wrapper">
                        <?php if (!$isActiveReceipt): ?><div class="void-watermark"><?= or_e(strtoupper($receiptStatus)) ?></div><?php endif; ?>
                        <article class="official-receipt">
                            <div class="or-frame">
                                <div class="or-main">
                                    <header class="or-header">
                                        <div class="or-org"><?= or_e(strtoupper($orIssuer['name'])) ?></div>
                                        <?php if ($orIssuer['address'] !== ''): ?><div class="or-address"><?= or_e(strtoupper($orIssuer['address'])) ?></div><?php endif; ?>
                                        <?php if ($orIssuer['phone'] !== ''): ?><div class="or-contact">TEL. NOS.: <?= or_e($orIssuer['phone']) ?></div><?php endif; ?>
                                        <?php if ($orIssuer['tin'] !== ''): ?><div class="or-tin">TIN <?= or_e($orIssuer['tin']) ?></div><?php endif; ?>
                                        <div class="or-date"><span>DATE:</span><span class="or-date-line" id="previewPaymentDate"><?= or_e($paymentDate) ?></span></div>
                                    </header>

                                    <div class="or-customer-grid">
                                        <div class="or-field"><span class="or-field-label">CUSTOMER NAME:</span><span class="or-field-value" id="previewCustomerName"><?= or_e($customerName) ?></span></div>
                                    </div>
                                    <div class="or-secondary-grid">
                                        <div class="or-field"><span class="or-field-label">BUSINESS STYLE:</span><span class="or-field-value"></span></div>
                                        <div class="or-field"><span class="or-field-label">OSCA/PWD NO.</span><span class="or-field-value"></span></div>
                                        <div class="or-field"><span class="or-field-label">SIGNATURE:</span><span class="or-field-value"></span></div>
                                    </div>

                                    <div class="service-heading">
                                        <div>IN PAYMENT OF THE FOLLOWING SERVICE/<br>TRANSACTION/DESCRIPTION</div>
                                        <div>AMOUNT ₱</div>
                                    </div>
                                    <table class="service-table <?= count($services) > $minimumServiceRows ? 'dense' : '' ?>" aria-label="Paid bill codes">
                                        <tbody>
                                        <?php for ($rowIndex = 0; $rowIndex < $serviceRowCount; $rowIndex++):
                                            $service = $services[$rowIndex] ?? null;
                                        ?>
                                            <tr>
                                                <td><?= $service ? or_e($service['description']) : '&nbsp;' ?></td>
                                                <td><?= $service ? '<span id="previewServiceAmount' . (int)$rowIndex . '">' . or_e(or_money($service['amount'])) . '</span>' : '&nbsp;' ?></td>
                                            </tr>
                                        <?php endfor; ?>
                                        </tbody>
                                    </table>
                                    <div class="or-footer-left">
                                        <div class="or-printer-space"></div>
                                        <div class="or-system-ref"></div>
                                    </div>
                                </div>

                                <aside class="or-side">
                                    <div class="or-side-top">
                                        <div class="or-validity">THIS OFFICIAL RECEIPT SHALL BE VALID FOR FIVE (5) YEARS FROM THE DATE OF ATP</div>
                                        <div class="or-number"><span class="or-no-label">NO.</span></div>
                                        <div class="or-title">OFFICIAL RECEIPT</div>
                                    </div>
                                    <div class="side-total"><span class="side-label">TOTAL SALES</span><span class="side-value preview-total-amount"><?= or_e(or_money($totalPaid)) ?></span></div>
                                    <div class="side-total"><span class="side-label">LESS: SC/PWD DISC.</span><span class="side-value"></span></div>
                                    <div class="side-total"><span class="side-label">TOTAL DUE</span><span class="side-value preview-total-amount"><?= or_e(or_money($totalPaid)) ?></span></div>
                                    <div class="side-total"><span class="side-label">LESS: WITHHOLDING</span><span class="side-value"></span></div>
                                    <div class="side-total"><span class="side-label">TOTAL AMOUNT DUE</span><span class="side-value preview-total-amount"><?= or_e(or_money($totalPaid)) ?></span></div>
                                    <div class="side-total"><span class="side-label">SALES SUBJ. TO PT</span><span class="side-value"></span></div>
                                    <div class="side-total"><span class="side-label">EXEMPT SALES</span><span class="side-value preview-total-amount"><?= or_e(or_money($totalPaid)) ?></span></div>
                                    <div class="side-payment">
                                        <span class="payment-box"></span>CASH
                                        <span class="payment-box"></span>CHECK
                                        <span class="payment-box"></span>CREDIT
                                    </div>
                                    <div class="cashier-box">
                                        <div class="cashier-name"></div>
                                        <div class="cashier-label">CASHIER/AUTHORIZED PERSON</div>
                                    </div>
                                    <div class="invalid-tax">“THIS DOCUMENT IS NOT VALID<br>FOR CLAIMING INPUT TAXES”</div>
                                </aside>
                            </div>
                        </article>
                    </div>
                    <?php endif; ?>
                </section>
            </div>
        <?php endif; ?>
    </main>
</div>
<script>
(function () {
    const search = document.getElementById('receiptSearch');
    const rows = Array.from(document.querySelectorAll('.receipt-row'));
    if (search) {
        search.addEventListener('input', function () {
            const query = this.value.trim().toLowerCase();
            rows.forEach(row => {
                row.style.display = !query || row.textContent.toLowerCase().includes(query) ? '' : 'none';
            });
        });
    }

    const customerNameInput = document.getElementById('editCustomerName');
    const customerNamePreview = document.getElementById('previewCustomerName');
    if (customerNameInput && customerNamePreview) {
        customerNameInput.addEventListener('input', () => {
            customerNamePreview.textContent = customerNameInput.value.trim().toUpperCase();
        });
    }

    const paymentDateInput = document.getElementById('editPaymentDate');
    const paymentDatePreview = document.getElementById('previewPaymentDate');
    const formatDate = value => {
        if (!value) return '';
        const parts = value.split('-');
        return parts.length === 3 ? `${parts[1]}/${parts[2]}/${parts[0]}` : value;
    };
    if (paymentDateInput && paymentDatePreview) {
        paymentDateInput.addEventListener('input', () => {
            paymentDatePreview.textContent = formatDate(paymentDateInput.value);
        });
    }

    const formatMoney = value => {
        const amount = Number.parseFloat(value || '0');
        return Number.isFinite(amount)
            ? amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
            : '0.00';
    };

    const totalAmountInput = document.getElementById('editTotalAmount');
    const totalAmountPreviews = Array.from(document.querySelectorAll('.preview-total-amount'));
    const refreshTotal = () => {
        const formatted = formatMoney(totalAmountInput ? totalAmountInput.value : 0);
        totalAmountPreviews.forEach(node => { node.textContent = formatted; });
    };
    if (totalAmountInput) {
        totalAmountInput.addEventListener('input', refreshTotal);
    }

    document.querySelectorAll('.service-amount-input').forEach(input => {
        input.addEventListener('input', () => {
            const preview = document.getElementById(input.dataset.previewId || '');
            if (preview) preview.textContent = formatMoney(input.value);
        });
    });

    /* the pre-printed official receipt (version 46): its entries follow the values above, each in its own format */
    const prePage = document.querySelector('.receipt-wrapper.is-preprinted .wrl-page');
    if (prePage) {
        const MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        const pad = n => String(n).padStart(2, '0');
        const preDate = (v, f) => {
            const p = String(v || '').split('-');
            if (p.length !== 3) return '';
            const y = +p[0], m = +p[1], d = +p[2];
            switch (f) {
                case 'n/j/y': return m + '/' + d + '/' + pad(y % 100);
                case 'm/d/y': return pad(m) + '/' + pad(d) + '/' + pad(y % 100);
                case 'M j, Y': return MON[m - 1] + ' ' + d + ', ' + y;
                case 'd-M-y': return pad(d) + '-' + MON[m - 1] + '-' + pad(y % 100);
                default: return pad(m) + '/' + pad(d) + '/' + y;
            }
        };
        const preMoney = (v, f, z) => {
            const n = Number.parseFloat(v || '0') || 0;
            if (Math.abs(n) < 0.005) return z === '-' ? '-' : (z === '0' ? (f === 'whole' ? '0' : '0.00') : '');
            const a = Math.abs(n);
            const t = f === 'plain' ? a.toFixed(2) : (f === 'whole' ? Math.round(a).toLocaleString('en-US') : a.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
            return (n < 0 ? '-' : '') + t;
        };
        const put = (field, value) => prePage.querySelectorAll('[data-f="' + field + '"]').forEach(el => {
            el.textContent = el.dataset.kind === 'money' ? preMoney(value, el.dataset.fmt, el.dataset.z) : (el.dataset.kind === 'date' ? preDate(value, el.dataset.fmt) : value);
        });
        if (customerNameInput) customerNameInput.addEventListener('input', () => put('name', customerNameInput.value.trim().toUpperCase()));
        if (paymentDateInput) paymentDateInput.addEventListener('input', () => put('date', paymentDateInput.value));
        if (totalAmountInput) totalAmountInput.addEventListener('input', () => ['total_sales', 'total_due', 'total_amount_due', 'exempt_sales'].forEach(f => put(f, totalAmountInput.value)));
        const amountInputs = Array.from(document.querySelectorAll('.service-amount-input'));
        const refreshLines = () => prePage.querySelectorAll('[data-f="amount"][data-src]').forEach(el => {
            // a line stands for one service, or for the services that did not fit (added together)
            const sum = String(el.dataset.src).split(',').reduce((t, i) => {
                const inp = amountInputs.find(x => x.dataset.line === i);
                return t + (inp ? Number.parseFloat(inp.value || '0') || 0 : 0);
            }, 0);
            el.textContent = preMoney(sum, el.dataset.fmt, el.dataset.z);
        });
        amountInputs.forEach(inp => inp.addEventListener('input', refreshLines));

        // the form is shown whole on narrow screens (scaled down); it prints at its own size
        const preFit = document.getElementById('orPreFit');
        const preWrap = prePage.closest('.receipt-wrapper');
        const fitPre = () => {
            if (!preFit || !preWrap) return;
            preFit.style.transform = 'none'; preFit.style.width = ''; preFit.style.height = '';
            const w = prePage.offsetWidth, h = prePage.offsetHeight;
            const scale = Math.min(1, Math.max(0.2, preWrap.clientWidth / w));
            preFit.style.transform = 'scale(' + scale + ')';
            preFit.style.width = (w * scale) + 'px';
            preFit.style.height = (h * scale) + 'px';
        };
        window.addEventListener('resize', () => window.requestAnimationFrame(fitPre));
        window.addEventListener('beforeprint', () => { if (preFit) { preFit.style.transform = 'none'; preFit.style.width = ''; preFit.style.height = ''; } });
        window.addEventListener('afterprint', fitPre);
        window.addEventListener('load', fitPre);
        fitPre();
    }

    const printButton = document.getElementById('printOfficialReceipt');
    if (printButton) {
        printButton.addEventListener('click', () => window.print());
    }
})();
</script>
</body>
</html>
