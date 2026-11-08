<?php
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../process/accessControlHelpers.php';
require_once __DIR__ . '/../common/collectionReceiptHelpers.php';
require_once __DIR__ . '/../common/institutionConfigurationHelpers.php';

$pdo = getDB();
// the cooperative issues the receipt (company settings, then the institution profile)
$receiptIssuer = institution_issuer($pdo);
requirePageAccess($pdo, basename(__FILE__, '.php'));

$receiptNo = trim((string)($_GET['receipt_no'] ?? ''));
$autoPrint = isset($_GET['autoprint']) && $_GET['autoprint'] === '1';
try {
    $receipt = $receiptNo !== '' ? cr_fetch_receipt($pdo, $receiptNo) : null;
} catch (Throwable $e) {
    error_log('[collection_receipt] ' . $e->getMessage());
    http_response_code(500);
    exit('Receipt data could not be loaded or reconciled. Please verify the transaction and try again.');
}

if (!$receipt) {
    http_response_code(404);
    exit('Receipt not found.');
}

function re($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * One copy of the water bill / collection receipt printed on plain paper (version 57): Letter bond paper, portrait,
 * the Cooperative's copy on the upper half and the Consumer's copy on the lower half, cut apart on the dashed line.
 * Black text only; the entries in bold Courier New, the labels in bold Arial, sized for an Epson dot-matrix printer.
 * The Pre-Printed receipt of Receipt Layout Setup prints the entries where this one prints them.
 */
function renderLetterReceiptCopy(array $layout, string $copyLabel, array $issuer = []): void
{
    // the lines of charges that fit under the water fee and the arrears: the rest together on the last one
    $fees = array_values((array)($layout['other_fee_items'] ?? []));
    if (count($fees) > 3) {
        $rest = 0.0;
        foreach (array_slice($fees, 2) as $f) {
            $rest += (float)str_replace(',', '', (string)$f['amount']);
        }
        $fees = array_merge(array_slice($fees, 0, 2), [['description' => 'OTHER CHARGES', 'amount' => number_format($rest, 2)]]);
    }
    $issuerLine = trim(implode(', ', array_filter([trim((string)($issuer['address'] ?? '')), ($issuer['phone'] ?? '') !== '' ? 'Tel. No. ' . $issuer['phone'] : ''])));
    ?>
    <section class="wb-copy" aria-label="<?= re($copyLabel) ?>">
        <header class="wb-head">
            <div class="wb-org"><?= re(mb_strtoupper((string)($issuer['name'] ?? ''), 'UTF-8')) ?></div>
            <div class="wb-org-sub"><?= re($issuerLine) ?></div>
            <div class="wb-title-row">
                <span class="wb-copy-label"><?= re($copyLabel) ?></span>
                <span class="wb-title">WATER BILL</span>
                <span class="wb-title-side" aria-hidden="true"></span>
            </div>
        </header>
        <div class="wb-frame">
            <div class="wb-row wb-row-a">
                <div class="wb-cell wb-c-account"><span class="wb-l">Account #</span><b class="dm" data-wb="account"><?= re($layout['account_number']) ?></b></div>
                <div class="wb-cell wb-c-name"><span class="wb-l">Concessionaire's Name</span><b class="dm wb-big" data-wb="name" data-fit-text data-fit-min-ratio="0.72" title="<?= re($layout['concessionaire_name']) ?>"><?= re($layout['concessionaire_name']) ?></b></div>
                <div class="wb-cell wb-c-number"><span class="wb-l">Number</span><b class="dm wb-small" data-wb="number" data-fit-text data-fit-min-ratio="0.7"><?= re($layout['receipt_number']) ?></b></div>
            </div>
            <div class="wb-row wb-row-b">
                <div class="wb-cell wb-c-address"><span class="wb-l wb-l-inline">Address</span><b class="dm" data-wb="address" data-fit-text data-fit-min-ratio="0.7" title="<?= re($layout['address']) ?>"><?= re($layout['address']) ?></b></div>
                <div class="wb-cell wb-c-rate"><span class="wb-l wb-l-inline">Rate Code</span><b class="dm" data-wb="rate_code"><?= re($layout['rate_code']) ?></b></div>
            </div>
            <div class="wb-row wb-row-c">
                <div class="wb-cell wb-c-dates">
                    <div class="wb-sec">Date / Meter Reading</div>
                    <div class="wb-kv"><span>Previous Rdng</span><b class="dm" data-wb="prev_date"><?= re($layout['previous_reading_date']) ?></b></div>
                    <div class="wb-kv"><span>Present Rdng</span><b class="dm" data-wb="pres_date"><?= re($layout['present_reading_date']) ?></b></div>
                    <div class="wb-kv"><span>Due Date</span><b class="dm" data-wb="due_date"><?= re($layout['due_date']) ?></b></div>
                    <div class="wb-kv"><span>Charge Mode</span><b class="dm" data-wb="charge_mode"><?= re($layout['charge_mode']) ?></b></div>
                    <div class="wb-kv"><span>Disconnection</span><b class="dm" data-wb="disconnection"><?= re($layout['disconnection_date']) ?></b></div>
                </div>
                <div class="wb-cell wb-c-meter">
                    <div class="wb-readings">
                        <div><span class="wb-l">Present</span><b class="dm wb-big" data-wb="present"><?= re($layout['present_reading']) ?></b></div>
                        <div><span class="wb-l">Previous</span><b class="dm wb-big" data-wb="previous"><?= re($layout['previous_reading']) ?></b></div>
                        <div><span class="wb-l">Usage (m3)</span><b class="dm wb-big" data-wb="usage"><?= re($layout['usage']) ?></b></div>
                    </div>
                    <div class="wb-charges">
                        <div class="wb-charge"><span>WATER FEE</span><b class="dm" data-wb="water_fee"><?= re($layout['water_fee']) ?></b></div>
                        <div class="wb-charge"><span>ARREARS</span><b class="dm" data-wb="arrears"><?= re($layout['arrears']) ?></b></div>
                        <?php foreach ($fees as $i => $fee): ?>
                        <div class="wb-charge"><span class="dm" data-wb="line<?= (int)$i ?>" title="<?= re($fee['description']) ?>"><?= re($fee['description']) ?></span><b class="dm" data-wb="line<?= (int)$i ?>_amount"><?= re($fee['amount']) ?></b></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="wb-row wb-row-d">
                <div class="wb-cell wb-c-paid"><b class="dm" data-wb="paid_at" data-fit-text data-fit-min-ratio="0.75"><?= re($layout['date_time_of_payment']) ?></b><span class="wb-l">Date - Time of Payment</span></div>
                <div class="wb-cell wb-c-sign"><b class="dm" aria-hidden="true">&nbsp;</b><span class="wb-l">Received by / Signature</span></div>
                <div class="wb-cell wb-c-total"><b class="dm wb-total" data-wb="amount_due"><?= re($layout['amount_due']) ?></b><span class="wb-l">Total Amount Due</span></div>
            </div>
            <div class="wb-row wb-row-e">
                <div class="wb-cell wb-c-words"><span class="wb-l wb-l-inline">Amount in Words</span><b class="dm" data-wb="words" data-fit-text data-fit-min-ratio="0.72" title="<?= re($layout['amount_in_words']) ?>"><?= re($layout['amount_in_words']) ?></b></div>
            </div>
            <div class="wb-row wb-row-f">
                <div class="wb-cell wb-c-valid">This receipt is not valid unless signed by our collector</div>
                <div class="wb-cell wb-c-money"><b class="dm" data-wb="cash"><?= re($layout['cash']) ?></b><span class="wb-l">Cash</span></div>
                <div class="wb-cell wb-c-money"><b class="dm" data-wb="amount_due2"><?= re($layout['amount_due']) ?></b><span class="wb-l">Amt. Due</span></div>
                <div class="wb-cell wb-c-money"><b class="dm" data-wb="change"><?= re($layout['change']) ?></b><span class="wb-l">Change</span></div>
            </div>
        </div>
    </section>
    <?php
}

$layout = $receipt['layout'];
$status = strtoupper((string)($receipt['transaction_status'] ?? 'ACTIVE'));
$receiptPrintCsrf = csrfToken();

// Printed (the whole form on plain paper) or Pre-Printed (only the entries, on the cooperative's own forms): the
// company's choice (Company, Security & Closing Date > Water Billing); ?type= prints the other way once (version 46)
$printType = WaterReceiptLayout::type('collection');
if (isset($_GET['type']) && isset(WaterReceiptLayout::TYPES[(string)$_GET['type']])) {
    $printType = (string)$_GET['type'];
}
if ($printType === 'preprinted') {
    $preLayout = WaterReceiptLayout::get('collection');
    $pre = WaterReceiptLayout::collectionValues($layout, (string)($receipt['cashier_name'] ?? ''));
    $ps = $preLayout['setup'];
    $inch = fn(int $tw) => rtrim(rtrim(number_format($tw / 1440, 2, '.', ''), '0'), '.');
    $otherUrl = 'collection_receipt.php?receipt_no=' . urlencode($receiptNo) . '&type=printed';
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Water Bill Receipt <?= re($receiptNo) ?> (pre-printed form)</title>
    <style>
        body { margin: 0; background: #edf1f5; font-family: Arial, Helvetica, sans-serif; color: #111827; }
        .receipt-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; min-height: 62px; padding: 10px 18px; background: #01082d; color: #fff; }
        .receipt-toolbar button, .receipt-toolbar a { min-height: 40px; padding: 9px 15px; border: 0; border-radius: 6px; font: 700 14px Arial, Helvetica, sans-serif; text-decoration: none; cursor: pointer; display: inline-flex; align-items: center; }
        .receipt-toolbar button { background: #fff; color: #01082d; }
        .receipt-toolbar a { background: rgba(255, 255, 255, .14); color: #fff; }
        .receipt-meta { font-size: 13px; line-height: 1.4; opacity: .9; }
        .status-warning { padding: 6px 10px; border-radius: 6px; background: #fef2f2; color: #b91c1c; font-weight: 700; font-size: 13px; }
        .receipt-preview { padding: 26px; overflow: auto; }
        .wrl-fit { transform-origin: top left; }
        <?= WaterReceiptLayout::css($preLayout) ?>
        @media print {
            html, body { width: <?= $inch($ps['page_width']) ?>in; height: <?= $inch($ps['page_height']) ?>in; margin: 0 !important; padding: 0 !important; background: #fff; overflow: hidden; }
            .receipt-toolbar { display: none !important; }
            .receipt-preview { padding: 0 !important; overflow: visible; }
            .wrl-fit { transform: none !important; width: auto !important; height: auto !important; }
            .wrl-page { box-shadow: none !important; outline: 0 !important; }
            .wrl-area { outline: 0 !important; }
        }
        @media (max-width: 640px) { .receipt-preview { padding: 14px; } .receipt-toolbar { padding: 10px 12px; } }
    </style>
</head>
<body data-autoprint="<?= $autoPrint ? '1' : '0' ?>"
      data-receipt-no="<?= re($receiptNo) ?>"
      data-print-endpoint="../process/registerReceiptPrint.php"
      data-print-csrf="<?= re($receiptPrintCsrf) ?>">
    <nav class="receipt-toolbar" aria-label="Receipt controls">
        <button type="button" id="printReceiptButton">Print Receipt</button>
        <a href="payment_history.php">Payment History</a>
        <a href="water_bills_collection.php">Collection</a>
        <a href="<?= re($otherUrl) ?>" title="Print the whole receipt on plain paper this time">Print on plain paper</a>
        <span class="receipt-meta">Receipt <?= re($receiptNo) ?>, pre-printed form <?= re($inch($ps['page_width'])) ?> &times; <?= re($inch($ps['page_height'])) ?> in: only the entries print. Print at Actual Size / 100%.</span>
        <?php if ($status !== 'ACTIVE'): ?>
            <span class="status-warning"><?= re($status) ?><?= !empty($receipt['reversal_reason']) ? ': ' . re($receipt['reversal_reason']) : '' ?></span>
        <?php endif; ?>
    </nav>
    <main class="receipt-preview" id="receiptPreview">
        <div class="wrl-fit" id="receiptSheet"><?= WaterReceiptLayout::render('collection', $preLayout, $pre['values'], $pre['lines']) ?></div>
    </main>
    <script src="../common/receipt-print.js"></script>
</body>
</html>
    <?php
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Water Bill Receipt <?= re($receiptNo) ?></title>
    <link rel="stylesheet" href="../resources/css/cowassco_measured_receipt.css?v=<?= (int)@filemtime(__DIR__ . '/../resources/css/cowassco_measured_receipt.css') ?>">
</head>
<body data-autoprint="<?= $autoPrint ? '1' : '0' ?>"
      data-receipt-no="<?= re($receiptNo) ?>"
      data-print-endpoint="../process/registerReceiptPrint.php"
      data-print-csrf="<?= re($receiptPrintCsrf) ?>">
    <nav class="receipt-toolbar" aria-label="Receipt controls">
        <button type="button" id="printReceiptButton">Print Receipt</button>
        <a href="payment_history.php">Payment History</a>
        <a href="water_bills_collection.php">Collection</a>
        <a href="collection_receipt.php?receipt_no=<?= urlencode($receiptNo) ?>&amp;type=preprinted" title="Print only the entries, on a pre-printed form, this time">Print on a pre-printed form</a>
        <span class="receipt-meta">Receipt <?= re($receiptNo) ?>, Letter 8.5 &times; 11 in, portrait, two copies. Print at Actual Size / 100%.</span>
        <?php if ($status !== 'ACTIVE'): ?>
            <span class="status-warning"><?= re($status) ?><?= !empty($receipt['reversal_reason']) ? ': ' . re($receipt['reversal_reason']) : '' ?></span>
        <?php endif; ?>
    </nav>

    <main class="receipt-preview" id="receiptPreview">
        <div class="receipt-preview-scaler" id="receiptPreviewScaler">
            <article class="receipt-sheet" id="receiptSheet" aria-label="Water bill receipt on Letter paper">
                <?php renderLetterReceiptCopy($layout, "Cooperative's Copy", $receiptIssuer); ?>
                <div class="wb-cut" aria-hidden="true"><span>cut here</span></div>
                <?php renderLetterReceiptCopy($layout, "Consumer's Copy", $receiptIssuer); ?>
            </article>
        </div>
    </main>

    <script src="../resources/js/cowassco_measured_receipt.js?v=<?= (int)@filemtime(__DIR__ . '/../resources/js/cowassco_measured_receipt.js') ?>"></script>
</body>
</html>
