<?php
require_once __DIR__ . '/../database/config.php';

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

function renderCowasscoMeasuredReceiptCopy(array $layout, string $copyLabel, array $issuer = []): void
{
    // The measured layout leaves room for the serial of a pre-printed form; printed on plain paper it stays blank.
    // The system transaction reference remains inside the measured Number cell.
    $formSerialNumber = '';
    ?>
    <section class="receipt-copy" aria-label="<?= re($copyLabel) ?>">
        <section class="copy-inner">
            <header class="receipt-header">
                <div class="organization-block">
                    <div class="organization-name"><?= re(mb_strtoupper((string)($issuer['name'] ?? ''), 'UTF-8')) ?></div>
                    <div class="organization-address"><?= re($issuer['address'] ?? '') ?></div>
                </div>
                <div class="header-bottom-line">
                    <span class="telephone"><?= ($issuer['phone'] ?? '') !== '' ? 'Tel. No. ' . re($issuer['phone']) : '' ?></span>
                    <span class="bill-title">WATER BILL</span>
                    <span class="receipt-number dot-matrix" aria-label="Receipt form serial"><?= re($formSerialNumber) ?></span>
                </div>
            </header>

            <!-- Exact measured grid: 0.2 + 1.4 + 2.4 + 0.9 + 2.5 = 7.4 cm inside the 1.5 mm border. -->
            <div class="bill-frame">
                <section class="frame-row heading-row">
                    <div>Account #</div>
                    <div>Concessionaire's Name</div>
                    <div>Number</div>
                </section>

                <section class="frame-row consumer-row">
                    <div class="consumer-account dot-matrix"><?= re($layout['account_number']) ?></div>
                    <div class="consumer-information">
                        <div class="consumer-name dot-matrix" data-fit-text data-fit-min-ratio="0.62" title="<?= re($layout['concessionaire_name']) ?>"><?= re($layout['concessionaire_name']) ?></div>
                        <div class="consumer-number dot-matrix" data-fit-text data-fit-min-ratio="0.50" title="<?= re($layout['receipt_number']) ?>"><?= re($layout['receipt_number']) ?></div>
                        <div class="consumer-detail address-detail">
                            <span class="fixed-label">ADDRESS</span>
                            <span class="dot-matrix consumer-address-value" data-fit-text data-fit-min-ratio="0.58" title="<?= re($layout['address']) ?>"><?= re($layout['address']) ?></span>
                        </div>
                        <div class="consumer-detail">
                            <span class="fixed-label">Rate Code</span>
                            <span class="dot-matrix"><?= re($layout['rate_code']) ?></span>
                        </div>
                    </div>
                </section>

                <section class="frame-row meter-row">
                    <div class="date-meter-cell">
                        <div class="section-heading">DATE / METER READING</div>
                        <div class="date-list">
                            <div><span>Previous Rdng</span><b class="dot-matrix"><?= re($layout['previous_reading_date']) ?></b></div>
                            <div><span>Present Rdng</span><b class="dot-matrix"><?= re($layout['present_reading_date']) ?></b></div>
                            <div><span>Due Date</span><b class="dot-matrix"><?= re($layout['due_date']) ?></b></div>
                            <div><span>Charge Mode</span><b class="dot-matrix"><?= re($layout['charge_mode']) ?></b></div>
                            <div><span>Disconnection</span><b class="dot-matrix"><?= re($layout['disconnection_date']) ?></b></div>
                        </div>
                    </div>

                    <div class="meter-right-cell">
                        <div class="readings-strip">
                            <div class="reading-box present-box">
                                <div class="reading-title">Present</div>
                                <div class="reading-number dot-matrix"><?= re($layout['present_reading']) ?></div>
                                <div class="water-fee-label">WATER FEE</div>
                            </div>
                            <div class="reading-box previous-box">
                                <div class="reading-title">Previous</div>
                                <div class="reading-number dot-matrix"><?= re($layout['previous_reading']) ?></div>
                            </div>
                            <div class="reading-box usage-box">
                                <div class="reading-title">Usage (m3)</div>
                                <div class="reading-number dot-matrix"><?= re($layout['usage']) ?></div>
                                <div class="water-fee-value dot-matrix"><?= re($layout['water_fee']) ?></div>
                            </div>
                        </div>

                        <div class="charges-open-cell">
                            <div class="charge-entry arrears-entry">
                                <span>ARREARS</span><b class="dot-matrix"><?= re($layout['arrears']) ?></b>
                            </div>
                            <?php foreach (($layout['other_fee_items'] ?? []) as $otherFee): ?>
                            <div class="charge-entry other-fee-entry">
                                <span title="<?= re($otherFee['description']) ?>"><?= re($otherFee['description']) ?></span>
                                <b class="dot-matrix"><?= re($otherFee['amount']) ?></b>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </section>

                <section class="frame-row payment-row">
                    <div class="payment-cell payment-date-cell">
                        <div class="payment-value dot-matrix" data-fit-text data-fit-min-ratio="0.68"><?= re($layout['date_time_of_payment']) ?></div>
                        <div class="payment-label">DATE - TIME OF PAYMENT</div>
                    </div>
                    <div class="payment-cell signature-cell">
                        <div class="signature-value" aria-hidden="true"></div>
                        <div class="payment-label">RECEIVED BY / SIGNATURE</div>
                    </div>
                    <div class="payment-cell amount-due-cell">
                        <div class="payment-value total-due-value dot-matrix"><?= re($layout['amount_due']) ?></div>
                        <div class="payment-label total-label">Total Amount Due</div>
                    </div>
                </section>

                <section class="frame-row settlement-row">
                    <div class="amount-words-line">
                        <span>Amt in Words:</span>
                        <b class="dot-matrix" data-fit-text data-fit-min-ratio="0.56" title="<?= re($layout['amount_in_words']) ?>"><?= re($layout['amount_in_words']) ?></b>
                    </div>

                    <p class="receipt-validity">THIS RECEIPT IS NOT<br>VALID UNLESS SIGNED<br>BY OUR COLLECTOR</p>

                    <div class="settlement-values">
                        <div class="settlement-item">
                            <div class="dot-matrix"><?= re($layout['cash']) ?></div>
                            <span>CASH*****</span>
                        </div>
                        <div class="settlement-item">
                            <div class="dot-matrix"><?= re($layout['amount_due']) ?></div>
                            <span>AMT. DUE</span>
                        </div>
                        <div class="settlement-item">
                            <div class="dot-matrix"><?= re($layout['change']) ?></div>
                            <span>CHANGE</span>
                        </div>
                    </div>

                    <div class="copy-label"><?= re($copyLabel) ?></div>
                </section>
            </div>
        </section>
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
    <link rel="stylesheet" href="../resources/css/cowassco_measured_receipt.css?v=20260724-exact-editor">
<link rel="stylesheet" href="../common/ui-consistency.css">
<script src="../common/business-ui-consistency.js" defer></script>
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
        <span class="receipt-meta">Receipt <?= re($receiptNo) ?>, 22 cm × 10 cm, Print at Actual Size / 100%</span>
        <?php if ($status !== 'ACTIVE'): ?>
            <span class="status-warning"><?= re($status) ?><?= !empty($receipt['reversal_reason']) ? ': ' . re($receipt['reversal_reason']) : '' ?></span>
        <?php endif; ?>
    </nav>

    <main class="receipt-preview" id="receiptPreview">
        <div class="receipt-preview-scaler" id="receiptPreviewScaler">
            <article class="receipt-sheet" id="receiptSheet" aria-label="Measured water bill receipt">
                <?php renderCowasscoMeasuredReceiptCopy($layout, "Cooperative's Copy", $receiptIssuer); ?>
                <div class="center-perforation" aria-hidden="true"></div>
                <?php renderCowasscoMeasuredReceiptCopy($layout, "Consumer's Copy", $receiptIssuer); ?>
            </article>
        </div>
    </main>

    <script src="../resources/js/cowassco_measured_receipt.js?v=20260724-exact-editor"></script>
</body>
</html>
