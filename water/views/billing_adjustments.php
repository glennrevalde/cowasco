<?php
/**
 * Billing Adjustments: the controlled corrections of bills and payments (Super Administrator).
 *
 *   billing_adjustments.php               the bills, payment reversal / refund, debit and credit memos, delete
 *   billing_adjustments.php?edit=<key>    the bill editor: the bill itself (e.g. ?edit=one_time-8), its correctable
 *                                         fields in place, recalculated as they are typed, saved with a class and a
 *                                         reason through process/billLifecycleProcess.php (action=edit)
 */
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../process/accessControlHelpers.php';
require_once __DIR__ . '/../common/superAdminGuard.php';
require_once __DIR__ . '/../common/billLifecycleHelpers.php';
require_once __DIR__ . '/../common/billEditorHelpers.php';
require_once __DIR__ . '/../common/institutionConfigurationHelpers.php';
require_once __DIR__ . '/../process/organizationUnitHelpers.php';
$pdo = getDB();
requirePageAccess($pdo, basename(__FILE__, '.php'));
wb_require_super_admin('page');

if (!function_exists('e')) {
    function e($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
$csrf = csrfToken();
$flashSuccess = $_SESSION['success_msg'] ?? null;
$flashError = $_SESSION['error_msg'] ?? null;
unset($_SESSION['success_msg'], $_SESSION['error_msg']);

// ------------------------------------------------------------------ the bill editor
$doc = null;
$editKey = null;
if (isset($_GET['edit'])) {
    $editKey = be_parse_key((string)$_GET['edit']);
    try {
        if ($editKey === null) {
            throw new Exception('That bill could not be found.');
        }
        $doc = be_bill_document($pdo, $editKey[0], $editKey[1]);
    } catch (Throwable $x) {
        error_log('Bill editor: ' . $x->getMessage());
        $_SESSION['error_msg'] = $x instanceof PDOException ? 'The bill could not be opened. Please try again.' : $x->getMessage();
        header('Location: billing_adjustments.php');
        exit;
    }
}

if ($doc !== null) {
    $key = $doc['type'] . '-' . $doc['id'];
    $typed = [];
    if (($_SESSION['bill_editor_old']['bill'] ?? '') === $key) {
        $typed = $_SESSION['bill_editor_old'];
    }
    unset($_SESSION['bill_editor_old']);
    $issuer = institution_issuer($pdo);
    $logo = wa_issuer_logo($pdo);
    $acct = $doc['account'];
    $water = $doc['water'];
    $line = $doc['line'];
    $isWater = $doc['type'] === 'water';
    $quantityBased = $line !== null && $line['quantity_based'];
    $hasDue = in_array($doc['type'], ['one_time', 'recurring'], true);
    // the amount box holds the bill's total: on the line, or on the Total amount row when a discount comes off the line
    $amountInTotals = $line !== null && !$quantityBased && (float)$line['discount'] > 0;
    $canEdit = $doc['editable'];
    $present = $typed['pres_reading'] ?? ($water['present'] ?? '');
    $quantity = $typed['quantity'] ?? ($line !== null ? number_format((float)$line['quantity'], 2, '.', '') : '');
    $amount = $typed['total_amount'] ?? ($line !== null ? number_format((float)$line['total'], 2, '.', '') : '');
    $due = $typed['due_date'] ?? substr((string)($doc['due_date'] ?? ''), 0, 10);
    $confirm = ($isWater && !$doc['posted'])
        ? 'Save the corrected reading? It is not posted yet, so no journal entry is voided or posted.'
        : 'Save the correction? The bill\'s journal entry is voided and a correction entry is posted for the corrected amount (numbered ' . $doc['invoice_no'] . '-R' . ($doc['edit_count'] + 1) . ').';
    $periodText = '';
    if ($doc['period']) {
        $periodText = trim(($doc['period']['code'] !== '' ? $doc['period']['code'] . ', ' : '')
            . bl_date($doc['period']['start']) . ' to ' . bl_date($doc['period']['end']));
    }
    $pageTitle = ($canEdit ? 'Edit ' : 'View ') . $doc['type_label'] . ' ' . $doc['invoice_no'];
} else {
    $type = (string)($_GET['type'] ?? 'all');
    $search = trim((string)($_GET['search'] ?? ''));
    $showDeleted = !empty($_GET['show_deleted']);
    if (!in_array($type, ['all', 'water', 'one_time', 'installment', 'recurring'], true)) {
        $type = 'all';
    }
    $error = null;
    try {
        $records = bl_fetch_records($pdo, ['type' => $type, 'search' => $search, 'show_deleted' => $showDeleted]);
    } catch (Throwable $x) {
        $records = [];
        $error = $x->getMessage();
    }
    $collectedMap = bl_collected_bill_map($pdo);
    $labels = ['all' => 'All billing types', 'water' => 'Water billing', 'one_time' => 'One-time billing', 'installment' => 'Installment billing', 'recurring' => 'Recurring billing'];
    $pageTitle = 'Billing Adjustments';
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($pageTitle) ?> - <?= e(app_name()) ?></title>
<link rel="stylesheet" href="../common/fonts.css">
<style>
*{box-sizing:border-box}
[hidden]{display:none!important}
body{margin:0;background:#f7f8fb;color:#111827;font-family:'Sora',sans-serif}
.page{max-width:1500px;margin:0 auto;padding:28px 32px 70px}
.bar{display:flex;justify-content:space-between;align-items:flex-end;gap:16px;flex-wrap:wrap;margin-bottom:18px}
.bar h1{margin:0;font-size:1.45rem;letter-spacing:-.02em}
.bar > div{flex:1 1 480px;min-width:0}
.bar .sub{margin-top:5px;font-size:.86rem;color:#667085;line-height:1.55;max-width:900px}
.back{display:inline-flex;align-items:center;gap:6px;color:#667085;text-decoration:none;font-size:.8rem;font-weight:600;margin-bottom:8px}
.back:hover{color:#101828}
.card{background:#fff;border:1px solid #e4e7ec;border-radius:14px;margin-bottom:18px}
.card-body{padding:18px 20px}
.card-title{margin:0 0 14px;font-size:1rem;font-weight:700}
.notice{padding:13px 15px;border-radius:10px;margin-bottom:14px;font-size:.86rem;line-height:1.55}
.notice.info{background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8}
.notice.success{background:#ecfdf3;border:1px solid #a6f4c5;color:#027a48}
.notice.error{background:#fff1f3;border:1px solid #fecdd3;color:#be123c}
.notice.warn{background:#fffaeb;border:1px solid #fedf89;color:#b54708}
.field label{display:block;font-size:.8rem;font-weight:600;color:#475467;margin-bottom:6px}
.field input,.field select,.field textarea{width:100%;border:1px solid #d0d5dd;border-radius:9px;padding:10px 12px;font:inherit;font-size:.9rem;background:#fff;color:#101828}
.field textarea{min-height:84px;resize:vertical}
.field .water-class-field{margin:0;max-width:none}
.field .water-class-hint{font-size:.75rem;color:#667085;margin-top:6px}
.hint{font-size:.75rem;color:#667085;margin-top:6px;line-height:1.45}
.btn{border:0;border-radius:9px;padding:10px 15px;font:inherit;font-size:.86rem;font-weight:700;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:6px;background:#101828;color:#fff;white-space:nowrap}
.btn.light{background:#fff;color:#101828;border:1px solid #d0d5dd}
.btn.debit{background:#b54708}.btn.credit{background:#175cd3}.btn.danger{background:#b42318}.btn.edit{background:#027a48}
.btn.small{padding:7px 11px;font-size:.78rem}
.btn:disabled{opacity:.5;cursor:not-allowed}
.locked{font-size:.75rem;color:#98a2b3;font-weight:600;white-space:nowrap}
.pay-grid{display:grid;grid-template-columns:1.1fr .8fr .9fr 1.1fr 1.6fr auto;gap:12px;align-items:end}
.pay-grid .water-class-hint{display:none}
.filters{display:grid;grid-template-columns:210px 1fr 180px auto;gap:12px;align-items:end}
.filters .actions-row{display:flex;gap:8px}
.table-wrap{overflow:auto;max-height:72vh;border-radius:0 0 14px 14px}
table.records{width:100%;min-width:1180px;border-collapse:collapse;font-size:.8rem}
table.records th{position:sticky;top:0;z-index:1;background:#f9fafb;padding:11px 12px;text-align:left;font-size:.72rem;font-weight:700;color:#667085;border-bottom:1px solid #e4e7ec}
table.records td{padding:12px;border-bottom:1px solid #eaecf0;vertical-align:top}
table.records tr:hover td{background:#fcfcfd}
.num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.mono{font-family:'JetBrains Mono',monospace}
.muted{color:#667085;font-size:.75rem;margin-top:3px}
.edited{font-size:.72rem;color:#b54708;font-weight:600;margin-top:4px}
.st{font-weight:700;font-size:.78rem}
.st.paid{color:#027a48}.st.partial,.st.unpaid,.st.encoded{color:#b54708}.st.deleted,.st.cancelled{color:#667085}.st.overdue{color:#b42318}
.row-actions{display:flex;gap:6px;justify-content:flex-end;flex-wrap:wrap}
tr.deleted td{opacity:.58}tr.deleted td:first-child strong{text-decoration:line-through}
.modal-bg{position:fixed;inset:0;background:rgba(15,23,42,.6);display:none;align-items:center;justify-content:center;padding:16px;z-index:1060}
.modal-bg.open{display:flex}
.modal{width:min(560px,100%);max-height:calc(100vh - 32px);overflow:auto;background:#fff;border-radius:16px}
.modal-head{padding:16px 20px;border-bottom:1px solid #eaecf0;font-weight:700}
.modal-body{padding:18px 20px}
.modal-actions{padding:14px 20px;background:#f9fafb;border-top:1px solid #eaecf0;display:flex;gap:9px;justify-content:flex-end;flex-wrap:wrap}
.grid2{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.span2{grid-column:1/-1}

/* ---------------------------------------------------------------- the bill editor */
.editor{display:grid;grid-template-columns:minmax(0,1fr) 360px;gap:20px;align-items:start}
.paper{background:#fff;border:1px solid #d9dde4;border-radius:4px;box-shadow:0 1px 2px rgba(16,24,40,.05),0 14px 32px -16px rgba(16,24,40,.25);padding:34px 38px 30px;font-size:.86rem;line-height:1.45}
.doc-head{display:grid;grid-template-columns:minmax(0,1fr) minmax(220px,.75fr);gap:28px;align-items:start;padding-bottom:18px;border-bottom:2px solid #101828}
.doc-title{margin:0;font-size:1.35rem;font-weight:800;letter-spacing:.02em;text-transform:uppercase}
.doc-no{margin-top:6px;font-size:.85rem;color:#344054}
.doc-no strong{font-family:'JetBrains Mono',monospace}
.doc-state{margin-top:4px;font-size:.8rem;font-weight:700}
.issuer{display:flex;gap:12px;justify-content:flex-end;text-align:right}
.issuer img{width:58px;height:58px;object-fit:contain}
.issuer-name{font-weight:800;text-transform:uppercase;font-size:.85rem}
.issuer-line{font-size:.74rem;color:#475467}
.doc-parties{display:grid;grid-template-columns:minmax(0,1fr) minmax(240px,.8fr);gap:28px;margin:18px 0 20px}
.doc-label{font-size:.72rem;font-weight:700;color:#667085;margin-bottom:5px}
.bill-to-name{font-weight:800;text-transform:uppercase}
.bill-to-line{font-size:.8rem;color:#344054}
.meta{display:grid;grid-template-columns:max-content minmax(0,1fr);gap:6px 14px;font-size:.8rem;align-items:center}
.meta dt{color:#475467}
.meta dd{margin:0;font-weight:700;color:#101828}
.doc-table-wrap{overflow-x:auto;margin:6px 0 4px}
table.doc{width:100%;border-collapse:collapse;font-size:.8rem}
table.doc th{background:#f2f4f7;padding:8px 10px;text-align:left;font-size:.74rem;font-weight:700;color:#344054;white-space:nowrap}
table.doc td{padding:9px 10px;border-bottom:1px solid #eaecf0;vertical-align:middle}
table.doc .num{text-align:right}
table.doc tfoot td{border-bottom:0;font-weight:700}
.section-title{margin:22px 0 8px;font-size:.8rem;font-weight:800;color:#101828}
.edit-in{display:inline-flex;align-items:center;gap:6px;position:relative}
.edit-in input{width:140px;border:1.5px solid #84adff;background:#f5f8ff;border-radius:7px;padding:7px 9px;font:inherit;font-weight:700;font-variant-numeric:tabular-nums;text-align:right;color:#101828}
.edit-in input[type=date]{text-align:left;width:auto;min-width:150px}
.edit-in input:focus{outline:0;border-color:#2e90fa;box-shadow:0 0 0 3px rgba(46,144,250,.18)}
.edit-in input.changed{border-color:#027a48;background:#ecfdf3}
.edit-in input[aria-invalid=true]{border-color:#d92d20;background:#fef3f2}
.edit-mark{font-size:.68rem;font-weight:700;color:#175cd3}
.was{display:block;font-size:.7rem;color:#667085;font-weight:600;margin-top:3px}
.totals{margin:14px 0 0 auto;width:min(360px,100%);font-size:.84rem}
.totals div{display:flex;justify-content:space-between;gap:16px;padding:5px 10px}
.totals .grand{border-top:2px solid #101828;margin-top:4px;padding-top:9px;font-size:1rem;font-weight:800}
.totals .bal{font-weight:700}
.doc-foot{margin-top:24px;padding-top:12px;border-top:1px solid #eaecf0;font-size:.74rem;color:#667085;line-height:1.5}
.legend{display:flex;align-items:center;gap:8px;font-size:.76rem;color:#475467;margin-bottom:12px}
.legend i{display:inline-block;width:22px;height:14px;border:1.5px solid #84adff;background:#f5f8ff;border-radius:4px}
.side{position:sticky;top:84px;display:flex;flex-direction:column;gap:14px}
.side .card{margin:0}
.compare{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px}
.compare div{border:1px solid #eaecf0;border-radius:10px;padding:10px 12px}
.compare small{display:block;font-size:.72rem;color:#667085;font-weight:600}
.compare strong{display:block;margin-top:3px;font-size:1.02rem;font-variant-numeric:tabular-nums}
.change{font-size:.8rem;font-weight:700;margin-bottom:12px}
.change.up{color:#b54708}.change.down{color:#027a48}.change.none{color:#667085}
.preview-error{display:none;margin-bottom:12px}
.preview-error.show{display:block}
.side .btn{width:100%}
.side-actions{display:grid;gap:8px;margin-top:14px}
.busy{opacity:.55;transition:opacity .15s}
@media (max-width:1100px){
  .editor{grid-template-columns:1fr}
  .side{position:static}
}
@media (max-width:900px){
  .page{padding:20px 14px 60px}
  .pay-grid,.filters,.grid2{grid-template-columns:1fr}
  .filters .actions-row .btn{flex:1}
  .paper{padding:22px 16px}
  .doc-head,.doc-parties{grid-template-columns:1fr;gap:16px}
  .issuer{justify-content:flex-start;text-align:left}
  .issuer img{order:-1}
}
@media (max-width:760px){
  div.table-wrap{max-height:none;overflow:visible}
  div.table-wrap > table.records{min-width:0;width:100%}
  table.records thead{display:none}
  table.records,table.records tbody,table.records tr,table.records td{display:block;width:100%}
  table.records tr{border-bottom:1px solid #e4e7ec;padding:12px 14px}
  table.records td{border:0;padding:4px 0 4px 118px;position:relative;text-align:left;min-height:24px}
  table.records td::before{content:attr(data-label);position:absolute;left:0;top:5px;width:108px;font-family:'Sora',sans-serif;font-size:.72rem;font-weight:600;color:#667085}
  table.records td.num{text-align:left}
  table.records td.cell-actions{padding-left:0;margin-top:8px}
  table.records td.cell-actions::before{display:none}
  .row-actions{justify-content:flex-start}
  table.records td.empty{padding-left:0}
  table.records td.empty::before{display:none}
  /* the bill's own tables: one row of label and value per column */
  table.doc.stack thead{display:none}
  table.doc.stack,table.doc.stack tbody,table.doc.stack tr,table.doc.stack td{display:block;width:100%}
  table.doc.stack td{display:flex;justify-content:space-between;align-items:center;gap:12px;text-align:right;padding:8px 2px}
  table.doc.stack td::before{content:attr(data-label);font-family:'Sora',sans-serif;font-size:.74rem;font-weight:600;color:#475467;text-align:left;flex:0 0 auto}
  table.doc.stack td.desc{display:block;text-align:left}
  table.doc.stack td.desc::before{display:block;margin-bottom:3px}
  table.doc.stack .edit-in{justify-content:flex-end}
  .was{text-align:right}
  .edit-in input{width:120px}
  .totals{width:100%}
}
</style>
</head>
<body>
<?php require_once '../common/navbar.php'; ?>
<div class="main-content"><main class="page">
<?php if ($doc !== null): ?>
  <a class="back" href="billing_adjustments.php"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>Back to Billing Adjustments</a>
  <div class="bar">
    <div>
      <h1><?= $canEdit ? 'Edit' : 'View' ?> <?= e($doc['type_label']) ?> <?= e($doc['invoice_no']) ?></h1>
      <div class="sub"><?= $canEdit
          ? 'The bill as it stands. Correct it in the boxes; the bill is worked out again as you type. Then choose the class and give the reason, and save.'
          : 'This bill can no longer be edited.' ?></div>
    </div>
  </div>
  <?php if ($flashError): ?><div class="notice error" role="alert"><?= e($flashError) ?></div><?php endif; ?>
  <?php if (!$canEdit): ?>
    <div class="notice warn"><?= e($doc['locked_reason']) ?><?php if (!$doc['deleted']): ?> A collected bill is changed with a debit or credit memo, or by reversing its receipt, on <a href="billing_adjustments.php">Billing Adjustments</a>.<?php endif; ?></div>
  <?php endif; ?>

  <form class="editor" id="beForm" method="post" action="../process/billLifecycleProcess.php" autocomplete="off" novalidate>
    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
    <input type="hidden" name="action" value="edit">
    <input type="hidden" name="return" value="editor">
    <input type="hidden" name="bill_type" value="<?= e($doc['type']) ?>">
    <input type="hidden" name="bill_id" value="<?= (int)$doc['id'] ?>">

    <article class="paper" id="beBill" aria-label="<?= e($doc['type_label'] . ' ' . $doc['invoice_no']) ?>">
      <?php if ($canEdit): ?><div class="legend"><i></i>Boxes like this can be corrected.</div><?php endif; ?>
      <header class="doc-head">
        <div>
          <h2 class="doc-title"><?= e($doc['type_label']) ?></h2>
          <div class="doc-no">Invoice No. <strong><?= e($doc['invoice_no']) ?></strong></div>
          <div class="doc-state">Status: <?= e($doc['status']) ?></div>
        </div>
        <div class="issuer">
          <div>
            <div class="issuer-name"><?= e($issuer['name']) ?></div>
            <?php if ($issuer['address'] !== ''): ?><div class="issuer-line"><?= e($issuer['address']) ?></div><?php endif; ?>
            <?php if ($issuer['phone'] !== ''): ?><div class="issuer-line">Telephone: <?= e($issuer['phone']) ?></div><?php endif; ?>
            <?php if ($issuer['tin'] !== ''): ?><div class="issuer-line">TIN: <?= e($issuer['tin']) ?></div><?php endif; ?>
          </div>
          <?php if ($logo !== ''): ?><img src="<?= e($logo) ?>" alt=""><?php endif; ?>
        </div>
      </header>

      <div class="doc-parties">
        <div>
          <div class="doc-label">Billed to</div>
          <div class="bill-to-name"><?= e($acct['name']) ?></div>
          <div class="bill-to-line">Account no. <?= e($acct['account_number']) ?>, <?= e($acct['kind'] === 'Member' ? 'member' . ($acct['member_no'] !== '' ? ' no. ' . $acct['member_no'] : '') : 'customer') ?></div>
          <?php if ($acct['address'] !== ''): ?><div class="bill-to-line"><?= e($acct['address']) ?></div><?php endif; ?>
          <?php if ($acct['phone'] !== ''): ?><div class="bill-to-line"><?= e($acct['phone']) ?></div><?php endif; ?>
        </div>
        <dl class="meta">
          <?php if ($isWater): ?>
            <dt>Billing period</dt><dd><?= e($periodText ?: '—') ?></dd>
            <dt>Bill date</dt><dd><?= e($doc['posted'] ? bl_date($doc['bill_date']) : 'Not posted yet') ?></dd>
            <dt>Due date</dt><dd><?= e($doc['posted'] ? bl_date($doc['due_date']) : '—') ?></dd>
          <?php elseif ($doc['type'] === 'installment'): ?>
            <dt>Start date</dt><dd><?= e(bl_date($doc['bill_date'])) ?></dd>
            <dt>Payment</dt><dd><?= e($doc['installment']['payment_mode']) ?>, <?= (int)$doc['installment']['term'] ?> months</dd>
            <dt>Amortization</dt><dd><?= e($doc['installment']['amortization']) ?></dd>
          <?php else: ?>
            <?php if ($doc['type'] === 'recurring' && $doc['period']): ?><dt>Billing period</dt><dd><?= e($periodText) ?></dd><?php endif; ?>
            <dt>Bill date</dt><dd><?= e(bl_date($doc['bill_date'])) ?></dd>
            <dt><label for="beDue">Due date</label></dt>
            <dd>
              <?php if ($canEdit): ?>
                <span class="edit-in"><input type="date" id="beDue" name="due_date" value="<?= e($due) ?>" data-original="<?= e(substr((string)($doc['due_date'] ?? ''), 0, 10)) ?>" min="<?= e(substr((string)$doc['bill_date'], 0, 10)) ?>"<?= $doc['due_date'] === null ? ' placeholder="No due date"' : '' ?>></span>
              <?php else: ?>
                <?= e($doc['due_date'] ? bl_date($doc['due_date']) : 'No due date') ?>
              <?php endif; ?>
            </dd>
            <dt>Term</dt><dd id="beTerm"><?= ($doc['due_date'] && $doc['term_days'] !== null) ? (int)$doc['term_days'] . ' days' : '—' ?></dd>
            <?php if ($doc['disconnection_date'] || $doc['disconnection_days'] > 0): ?><dt>Disconnection date</dt><dd id="beDisc"><?= e($doc['disconnection_date'] ? bl_date($doc['disconnection_date']) : '—') ?></dd><?php endif; ?>
          <?php endif; ?>
          <?php if ($doc['class_name'] !== ''): ?><dt>Class</dt><dd><?= e($doc['class_name']) ?></dd><?php endif; ?>
        </dl>
      </div>

      <?php if ($isWater): ?>
        <div class="section-title">Meter reading</div>
        <div class="doc-table-wrap"><table class="doc stack">
          <thead><tr><th>Previous reading</th><th>Present reading</th><th class="num">Consumption</th></tr></thead>
          <tbody><tr>
            <td class="mono" data-label="Previous reading"><?= number_format((int)$water['previous']) ?> m³</td>
            <td data-label="Present reading"><div>
              <?php if ($canEdit): ?>
                <label class="edit-in"><input type="number" id="bePresent" name="pres_reading" inputmode="numeric" step="1" min="<?= (int)$water['previous'] ?>" value="<?= e($present) ?>" data-original="<?= (int)$water['present'] ?>" aria-label="Present reading (m³)"><span>m³</span></label>
                <span class="was" data-was>Was <?= number_format((int)$water['present']) ?> m³</span>
              <?php else: ?>
                <span class="mono"><?= number_format((int)$water['present']) ?> m³</span>
              <?php endif; ?>
            </div></td>
            <td class="num mono" data-label="Consumption"><span id="beConsumption"><?= number_format((int)$water['consumption']) ?></span> m³</td>
          </tr></tbody>
        </table></div>

        <div class="section-title">Charges</div>
        <div class="meta" style="margin-bottom:8px">
          <dt>Rate code</dt><dd id="beRate"><?= e($water['rate_code'] ?: '—') ?><?= $water['pipe'] !== '' ? ', ' . e($water['pipe']) : '' ?></dd>
          <?php if ($water['method'] !== ''): ?><dt>Computation</dt><dd id="beMethod"><?= e($water['method']) ?></dd><?php endif; ?>
        </div>
        <div class="doc-table-wrap"><table class="doc">
          <thead><tr><th>Consumption range</th><th>Rate</th><th class="num">m³</th><th class="num">Amount</th></tr></thead>
          <tbody id="beTiers">
          <?php foreach ($water['tiers'] as $t): ?>
            <tr><td><?= e($t['range']) ?></td><td><?= e($t['basis']) ?></td><td class="num mono"><?= number_format($t['m3']) ?></td><td class="num mono"><?= e(bl_money($t['amount'])) ?></td></tr>
          <?php endforeach; ?>
          <?php if (!$water['tiers']): ?><tr><td colspan="4" class="muted">The tariff lines of this bill were not kept (a bill from the former program); its amount is shown below.</td></tr><?php endif; ?>
          </tbody>
        </table></div>
        <div class="totals" id="beTotals">
          <div><span>Water charge</span><span class="mono" id="beBase"><?= e(bl_money($water['base_charge'])) ?></span></div>
          <div id="beFreeRow"<?= $water['free_discount'] > 0 ? '' : ' hidden' ?>><span>Free cubic meters (<span id="beFreeM3"><?= (int)$water['free_m3'] ?></span> m³)</span><span class="mono" id="beFree">- <?= e(bl_money($water['free_discount'])) ?></span></div>
          <div id="beOtherRow"<?= $water['other_discount'] > 0 ? '' : ' hidden' ?>><span>Privilege discount</span><span class="mono" id="beOther">- <?= e(bl_money($water['other_discount'])) ?></span></div>
          <div class="grand"><span>Amount due</span><span class="mono" id="beTotal"><?= e(bl_money($doc['total'])) ?></span></div>
          <?php if ($doc['paid'] > 0): ?><div class="bal"><span>Paid</span><span class="mono"><?= e(bl_money($doc['paid'])) ?></span></div><?php endif; ?>
          <?php if ($doc['posted']): ?><div class="bal"><span>Balance due</span><span class="mono" id="beBalance"><?= e(bl_money($doc['balance'])) ?></span></div><?php endif; ?>
        </div>
        <?php if ($water['warning'] !== ''): ?><div class="notice warn" style="margin-top:14px"><?= e($water['warning']) ?></div><?php endif; ?>
      <?php else: ?>
        <div class="section-title">Charge</div>
        <div class="doc-table-wrap"><table class="doc stack">
          <thead><tr><th>Bill code</th><th>Description</th><th class="num">Quantity</th><th class="num">Unit price</th><th class="num">Amount</th></tr></thead>
          <tbody><tr>
            <td data-label="Bill code"><strong><?= e($line['code']) ?></strong></td>
            <td class="desc" data-label="Description"><?= e($line['description'] !== '' ? $line['description'] : '—') ?><?php if ($line['code_type'] !== ''): ?><div class="muted"><?= e($line['code_type'] === 'Inventory' ? 'Material (inventory)' : $line['code_type']) ?></div><?php endif; ?></td>
            <td class="num" data-label="Quantity"><div>
              <?php if ($canEdit && $quantityBased): ?>
                <label class="edit-in"><input type="number" id="beQty" name="quantity" inputmode="decimal" step="0.01" min="0.01" value="<?= e($quantity) ?>" data-original="<?= e(number_format((float)$line['quantity'], 2, '.', '')) ?>" aria-label="Quantity"></label>
                <span class="was" data-was>Was <?= e(number_format((float)$line['quantity'], 2)) ?></span>
              <?php else: ?>
                <span class="mono"><?= e(number_format((float)$line['quantity'], 2)) ?></span>
              <?php endif; ?>
            </div></td>
            <td class="num mono" data-label="Unit price" id="beUnit"><?= e(bl_money($line['unit_price'])) ?></td>
            <td class="num" data-label="Amount"><div>
              <?php if ($canEdit && !$quantityBased && !$amountInTotals): ?>
                <label class="edit-in"><span>₱</span><input type="number" id="beAmount" name="total_amount" inputmode="decimal" step="0.01" min="0.01" value="<?= e($amount) ?>" data-original="<?= e(number_format((float)$line['total'], 2, '.', '')) ?>" aria-label="Amount"></label>
                <span class="was" data-was>Was <?= e(bl_money($line['total'])) ?></span>
              <?php else: ?>
                <span class="mono" id="beGrossCell"><?= e(bl_money($line['gross'])) ?></span>
              <?php endif; ?>
            </div></td>
          </tr></tbody>
        </table></div>
        <?php if ($quantityBased): ?><div class="hint">A material is priced from its bill code, so the quantity is corrected and the amount follows (the stock is moved by the difference).</div><?php endif; ?>
        <div class="totals" id="beTotals">
          <div><span>Gross amount</span><span class="mono" id="beGross"><?= e(bl_money($line['gross'])) ?></span></div>
          <?php if ($line['discount'] > 0): ?><div><span>Discount</span><span class="mono">- <?= e(bl_money($line['discount'])) ?></span></div><?php endif; ?>
          <?php if ($canEdit && $amountInTotals): ?>
            <div class="grand"><label for="beAmount">Total amount</label><span><span class="edit-in"><span>₱</span><input type="number" id="beAmount" name="total_amount" inputmode="decimal" step="0.01" min="0.01" value="<?= e($amount) ?>" data-original="<?= e(number_format((float)$line['total'], 2, '.', '')) ?>"></span><span class="was">Was <?= e(bl_money($line['total'])) ?>; the discount stays</span></span></div>
          <?php else: ?>
            <div class="grand"><span>Total amount</span><span class="mono" id="beTotal"><?= e(bl_money($doc['total'])) ?></span></div>
          <?php endif; ?>
          <?php if ($doc['paid'] > 0): ?><div class="bal"><span>Paid</span><span class="mono"><?= e(bl_money($doc['paid'])) ?></span></div><?php endif; ?>
          <div class="bal"><span>Balance due</span><span class="mono" id="beBalance"><?= e(bl_money($doc['balance'])) ?></span></div>
        </div>

        <?php if ($doc['type'] === 'installment'): ?>
          <div class="section-title">Payment schedule</div>
          <div class="doc-table-wrap"><table class="doc">
            <thead><tr><th>#</th><th>Due date</th><th class="num">Amount</th><th class="num">Paid</th><th>Status</th></tr></thead>
            <tbody id="beSchedule">
            <?php foreach ($doc['schedule'] as $i => $s): ?>
              <tr><td><?= $i + 1 ?></td><td><?= e(bl_date($s['due_date'])) ?></td><td class="num mono" data-i="<?= $i ?>"><?= e(bl_money($s['amount'])) ?></td><td class="num mono"><?= e(bl_money($s['amount_paid'])) ?></td><td><?= e(ucfirst((string)$s['status'])) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
          </table></div>
          <?php if ($canEdit): ?><div class="hint">A corrected total is spread again over the same due dates.</div><?php endif; ?>
        <?php endif; ?>
      <?php endif; ?>

      <footer class="doc-foot">
        <?php if ($doc['edit_count'] > 0): ?>
          Corrected <?= (int)$doc['edit_count'] ?> time<?= $doc['edit_count'] === 1 ? '' : 's' ?><?= $doc['last_edited_at'] ? ', last on ' . e(bl_date($doc['last_edited_at'])) : '' ?><?= $doc['revision_note'] !== '' ? ': ' . e($doc['revision_note']) : '' ?>.
        <?php else: ?>
          Not corrected before.
        <?php endif; ?>
        <?php if ($doc['type'] === 'one_time'): ?> The charge category and the date issued are changed on One-Time Billing (Update Bill Record).<?php endif; ?>
      </footer>
    </article>

    <aside class="side">
      <div class="card"><div class="card-body">
        <h2 class="card-title">Correction</h2>
        <div class="compare">
          <div><small>Now</small><strong class="mono"><?= e(bl_money($doc['total'])) ?></strong></div>
          <div><small>Corrected</small><strong class="mono" id="beNew"><?= e(bl_money($doc['total'])) ?></strong></div>
        </div>
        <div class="change none" id="beChange">No change yet.</div>
        <div class="hint" id="beReprice" hidden style="margin:-6px 0 12px"></div>
        <div class="notice error preview-error" id="beError" role="alert"></div>
        <?php if ($canEdit): ?>
          <div class="notice info" style="font-size:.8rem"><?= ($isWater && !$doc['posted'])
              ? 'This reading is not posted yet, so it has no journal entry: only the reading and its amount change.'
              : 'Saving voids this bill\'s journal entry and posts a correction entry for the corrected amount, numbered ' . e($doc['invoice_no']) . '-R' . ($doc['edit_count'] + 1) . '. The voided entry stays on file but is left out of every total.' ?></div>
          <div class="field" style="margin-bottom:12px"><?= render_water_class_field($typed['class_id'] ?? null, true, 'beClass', 'class_id', 'form-control', 'Class of the correction') ?></div>
          <div class="field">
            <label for="beReason">Reason</label>
            <textarea id="beReason" name="reason" minlength="5" maxlength="500" required placeholder="What was wrong with the bill"><?= e($typed['reason'] ?? '') ?></textarea>
          </div>
          <div class="side-actions">
            <button class="btn edit" type="submit" id="beSave" data-confirm="<?= e($confirm) ?>" disabled>Save correction</button>
            <a class="btn light" href="billing_adjustments.php">Cancel</a>
          </div>
        <?php else: ?>
          <div class="side-actions"><a class="btn light" href="billing_adjustments.php">Back to Billing Adjustments</a></div>
        <?php endif; ?>
      </div></div>
    </aside>
  </form>

<?php else: ?>
  <div class="bar">
    <div>
      <h1>Billing Adjustments</h1>
      <div class="sub">Edit a bill while nothing is collected on it, or use a debit or credit memo and a receipt reversal or refund once a payment exists, so receivables, allocations, receipts and journals stay reconciled.</div>
    </div>
    <a class="btn light" href="billing_management.php">Back to Billing</a>
  </div>
  <?php if ($flashSuccess): ?><div class="notice success" role="status"><?= e($flashSuccess) ?></div><?php endif; ?>
  <?php if ($flashError): ?><div class="notice error" role="alert"><?= e($flashError) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="notice error"><?= e($error) ?></div><?php endif; ?>
  <div class="notice info"><strong>Protected workflow:</strong> a bill may be edited only while nothing has been collected against it. Editing voids the original journal voucher and posts a replacement for the corrected amount; the voided voucher stays visible in the Journal Entry register but is left out of every total. Once an official receipt exists, use a reversal, refund, debit or credit instead.</div>

  <div class="card"><div class="card-body">
    <h2 class="card-title">Reverse or refund a payment</h2>
    <form class="pay-grid" method="post" action="../process/paymentAdjustmentProcess.php">
      <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
      <div class="field"><label for="payReceipt">Official receipt no.</label><input id="payReceipt" name="receipt_no" required placeholder="COL-..."></div>
      <div class="field"><label for="payAction">Action</label><select id="payAction" name="action"><option value="payment_reversal">Reversal</option><option value="payment_refund">Refund</option></select></div>
      <div class="field"><label for="payDate">Business date</label><input id="payDate" type="date" name="business_date" value="<?= date('Y-m-d') ?>" required></div>
      <div class="field"><?= render_water_class_field(null, true, 'payClassId') ?></div>
      <div class="field"><label for="payReason">Reason</label><input id="payReason" name="reason" minlength="5" required placeholder="Why the payment must be reversed or refunded"></div>
      <div><button class="btn danger" type="submit" data-confirm="This will restore all receivable balances and create a reversing journal. Continue?">Process</button></div>
    </form>
  </div></div>

  <div class="card"><div class="card-body">
    <form class="filters" method="get">
      <div class="field"><label for="fType">Bill type</label><select id="fType" name="type"><?php foreach ($labels as $k => $v): ?><option value="<?= e($k) ?>" <?= $type === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="fSearch">Search member or account</label><input id="fSearch" name="search" value="<?= e($search) ?>" placeholder="Name, account, member ID" data-live-search></div>
      <div class="field"><label for="fDeleted">Records</label><select id="fDeleted" name="show_deleted"><option value="0">Active only</option><option value="1" <?= $showDeleted ? 'selected' : '' ?>>Include deleted</option></select></div>
      <div class="actions-row"><button class="btn" type="submit">Apply</button><a class="btn light" href="billing_adjustments.php">Reset</a></div>
    </form>
  </div></div>

  <div class="card">
    <div class="table-wrap" id="adjRecords" data-live-results>
      <table class="records" data-ui-dense="keep">
        <thead><tr><th>Type and ID</th><th>Reference</th><th>Member</th><th>Description</th><th>Bill date</th><th>Due</th><th class="num">Total</th><th class="num">Paid</th><th class="num">Balance</th><th>Status</th><th class="num">Actions</th></tr></thead>
        <tbody>
        <?php if (!$records): ?><tr><td colspan="11" class="empty" style="text-align:center;padding:30px;color:#667085">No bill records found.</td></tr><?php endif; ?>
        <?php foreach ($records as $r):
            $deleted = bl_is_deleted($r);
            $paid = (float)($r['amount_paid'] ?? 0);
            $bt = (string)$r['bill_type'];
            $editable = bl_row_looks_editable($r, $collectedMap);
            $label = $r['source_name'] . ' #' . $r['bill_id'] . ' for ' . $r['member_name'];
            $status = strtolower((string)$r['status']);
            $statusText = ['encoded' => 'Not posted', 'partial' => 'Partly paid'][$status] ?? ucfirst($status);
        ?>
          <tr class="<?= $deleted ? 'deleted' : '' ?>">
            <td data-label="Type and ID"><strong><?= e($r['source_name']) ?></strong><div class="muted">#<?= e($r['bill_id']) ?></div></td>
            <td data-label="Reference" class="mono"><?= e($r['reference_label']) ?></td>
            <td data-label="Member"><strong><?= e($r['member_name']) ?></strong><div class="muted">Account <?= e($r['account_number'] ?: $r['member_id']) ?></div></td>
            <td data-label="Description"><?= e($r['description']) ?><?php if ((int)($r['bill_edit_count'] ?? 0) > 0): ?><div class="edited">Corrected <?= (int)$r['bill_edit_count'] ?> time<?= (int)$r['bill_edit_count'] === 1 ? '' : 's' ?></div><?php endif; ?></td>
            <td data-label="Bill date"><?= e(bl_date($r['bill_date'])) ?></td>
            <td data-label="Due"><?= e(bl_date($r['due_date'])) ?></td>
            <td data-label="Total" class="num mono"><?= e(bl_money($r['total_amount'])) ?></td>
            <td data-label="Paid" class="num mono"><?= e(bl_money($paid)) ?></td>
            <td data-label="Balance" class="num mono"><?= e(bl_money($r['balance'])) ?></td>
            <td data-label="Status"><span class="st <?= e($status) ?>"><?= e($statusText) ?></span></td>
            <td class="cell-actions"><div class="row-actions">
              <?php if (!$deleted): ?>
                <?php if ($editable): ?>
                  <a class="btn edit small" href="billing_adjustments.php?edit=<?= e(rawurlencode($bt . '-' . (int)$r['bill_id'])) ?>" title="Open the bill to correct it">Edit</a>
                <?php else: ?>
                  <span class="locked" title="Editing is locked once a collection exists">Collected, locked</span>
                <?php endif; ?>
                <button type="button" class="btn debit small" data-adjust="bill_debit" data-type="<?= e($bt) ?>" data-id="<?= (int)$r['bill_id'] ?>" data-label="<?= e($label) ?>">Debit</button>
                <button type="button" class="btn credit small" data-adjust="bill_credit" data-type="<?= e($bt) ?>" data-id="<?= (int)$r['bill_id'] ?>" data-label="<?= e($label) ?>">Credit</button>
                <?php if ($paid <= 0 && $bt !== 'water'): ?>
                  <button type="button" class="btn danger small" data-delete data-type="<?= e($bt) ?>" data-id="<?= (int)$r['bill_id'] ?>">Delete</button>
                <?php endif; ?>
              <?php else: ?>
                <span class="locked">Deleted <?= e(bl_date($r['deleted_at'] ?? null)) ?></span>
              <?php endif; ?>
            </div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>
</main></div>

<?php if ($doc === null): ?>
<div class="modal-bg" id="adjustModal" role="dialog" aria-modal="true" aria-labelledby="adjHead"><div class="modal">
  <div class="modal-head" id="adjHead">Controlled bill adjustment</div>
  <form method="post" action="../process/paymentAdjustmentProcess.php">
    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
    <input type="hidden" name="action" id="adjAction">
    <input type="hidden" name="bill_type" id="adjType">
    <input type="hidden" name="bill_id" id="adjId">
    <div class="modal-body">
      <p id="adjTitle" style="margin:0 0 14px;color:#475467"></p>
      <div class="grid2">
        <div class="field"><label for="adjAmount">Adjustment amount</label><input id="adjAmount" type="number" min="0.01" step="0.01" name="amount" required></div>
        <div class="field"><label for="adjDate">Business date</label><input id="adjDate" type="date" name="business_date" value="<?= date('Y-m-d') ?>" required></div>
        <div class="field span2"><?= render_water_class_field(null, true, 'adjClassId') ?></div>
        <div class="field span2"><label for="adjReason">Reason</label><textarea id="adjReason" name="reason" minlength="5" required></textarea></div>
      </div>
    </div>
    <div class="modal-actions"><button type="button" class="btn light" data-close>Cancel</button><button class="btn" type="submit">Post Adjustment</button></div>
  </form>
</div></div>
<div class="modal-bg" id="deleteModal" role="dialog" aria-modal="true" aria-labelledby="delHead"><div class="modal">
  <div class="modal-head" id="delHead">Delete unused bill</div>
  <form method="post" action="../process/billLifecycleProcess.php">
    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="bill_type" id="delType">
    <input type="hidden" name="bill_id" id="delId">
    <div class="modal-body">
      <div class="notice error">Only bills with no payment allocation may be deleted. Paid bills need a reversal or refund first.</div>
      <div class="field"><label for="delReason">Delete reason</label><textarea id="delReason" name="delete_reason" minlength="5" required></textarea></div>
    </div>
    <div class="modal-actions"><button type="button" class="btn light" data-close>Cancel</button><button class="btn danger" type="submit">Mark deleted</button></div>
  </form>
</div></div>
<script>
(function () {
  var $ = function (id) { return document.getElementById(id); };
  function open(m) { m.classList.add('open'); var f = m.querySelector('input:not([type=hidden]),textarea'); if (f) setTimeout(function () { f.focus(); }, 30); }
  function closeAll() { document.querySelectorAll('.modal-bg.open').forEach(function (m) { m.classList.remove('open'); }); }
  // the list is reloaded by the live search, so the buttons are found by delegation
  document.addEventListener('click', function (ev) {
    var a = ev.target.closest('[data-adjust]');
    if (a) {
      $('adjType').value = a.dataset.type; $('adjId').value = a.dataset.id; $('adjAction').value = a.dataset.adjust;
      $('adjTitle').textContent = (a.dataset.adjust === 'bill_credit' ? 'Credit: ' : 'Debit: ') + a.dataset.label;
      open($('adjustModal'));
      return;
    }
    var d = ev.target.closest('[data-delete]');
    if (d) { $('delType').value = d.dataset.type; $('delId').value = d.dataset.id; open($('deleteModal')); return; }
    if (ev.target.closest('[data-close]') || ev.target.classList.contains('modal-bg')) closeAll();
  });
  document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') closeAll(); });
})();
</script>
<?php elseif ($canEdit): ?>
<script>
(function () {
  var form = document.getElementById('beForm');
  var save = document.getElementById('beSave');
  var errorBox = document.getElementById('beError');
  var oldTotal = <?= json_encode(round((float)$doc['total'], 2)) ?>;
  var paid = <?= json_encode(round((float)$doc['paid'], 2)) ?>;
  var billKey = <?= json_encode($key) ?>;
  var isWater = <?= $isWater ? 'true' : 'false' ?>;
  var inputs = Array.prototype.slice.call(form.querySelectorAll('input[data-original]'));
  var seq = 0, timer = null, previewOk = true;
  // the bill as it stands: shown again whenever nothing is changed
  var keep = ['beNew', 'beTotal', 'beBalance', 'beConsumption', 'beBase', 'beFreeRow', 'beOtherRow', 'beTiers', 'beRate',
              'beUnit', 'beGross', 'beGrossCell', 'beTerm', 'beDisc', 'beSchedule'];
  var stored = {};
  keep.forEach(function (id) { var el = document.getElementById(id); if (el) stored[id] = { html: el.innerHTML, hidden: el.hidden }; });

  function money(v) {
    var n = Number(v || 0);
    return '₱ ' + n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }
  function dateText(iso) {
    if (!iso || !/^\d{4}-\d{2}-\d{2}$/.test(iso)) return '—';
    var p = iso.split('-'), m = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return m[+p[1] - 1] + ' ' + (+p[2]) + ', ' + p[0];
  }
  function text(id, v) { var el = document.getElementById(id); if (el) el.textContent = v; }
  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function restore() {
    Object.keys(stored).forEach(function (id) { var el = document.getElementById(id); el.innerHTML = stored[id].html; el.hidden = stored[id].hidden; });
  }
  // a number is changed when its value is (2 and 2.00 are the same); an empty due date keeps the bill's due date
  // (the date box is also empty while a date is half typed)
  function differs(i) {
    var v = String(i.value).trim(), o = String(i.dataset.original).trim();
    if (i.type === 'date') return v !== '' && v !== o;
    if (i.type === 'number') {
      if (v === '') return true;
      var a = Number(v), b = Number(o);
      return !isFinite(a) || Math.abs(a - b) >= 0.005;
    }
    return v !== o;
  }
  function changed() { return inputs.some(differs); }
  function mark() { inputs.forEach(function (i) { i.classList.toggle('changed', differs(i)); }); }
  function setError(msg, input) {
    errorBox.textContent = msg || '';
    errorBox.classList.toggle('show', !!msg);
    inputs.forEach(function (i) { i.removeAttribute('aria-invalid'); });
    if (msg && input) input.setAttribute('aria-invalid', 'true');
  }
  function refreshSave(total) {
    var diff = Math.round((total - oldTotal) * 100) / 100;
    var box = document.getElementById('beChange');
    if (!changed()) {
      box.className = 'change none'; box.textContent = 'No change yet.';
    } else if (Math.abs(diff) < 0.005) {
      box.className = 'change none'; box.textContent = 'The amount stays the same.';
    } else {
      box.className = 'change ' + (diff > 0 ? 'up' : 'down');
      box.textContent = (diff > 0 ? 'Up by ' : 'Down by ') + money(Math.abs(diff)) + '.';
    }
    save.disabled = !previewOk || !changed();
  }
  function apply(b) {
    text('beNew', money(b.total));
    text('beTotal', money(b.total));
    text('beBalance', money(Math.max(0, b.total - paid)));
    if (b.type === 'water') {
      var w = b.water;
      text('beConsumption', Number(w.consumption).toLocaleString('en-US'));
      text('beBase', money(w.base_charge));
      var fr = document.getElementById('beFreeRow'), or = document.getElementById('beOtherRow');
      if (fr) { fr.hidden = !(w.free_discount > 0); text('beFree', '- ' + money(w.free_discount)); text('beFreeM3', w.free_m3); }
      if (or) { or.hidden = !(w.other_discount > 0); text('beOther', '- ' + money(w.other_discount)); }
      var rows = w.tiers.map(function (t) {
        return '<tr><td>' + esc(t.range) + '</td><td>' + esc(t.basis) + '</td><td class="num mono">' + Number(t.m3).toLocaleString('en-US') + '</td><td class="num mono">' + money(t.amount) + '</td></tr>';
      });
      document.getElementById('beTiers').innerHTML = rows.join('') || '<tr><td colspan="4" class="muted">No charge at this consumption.</td></tr>';
      var rate = document.getElementById('beRate');
      if (rate && w.rate_code) rate.textContent = w.rate_code + (w.pipe ? ', ' + w.pipe : '');
    } else {
      text('beUnit', money(b.unit_price));
      text('beGross', money(b.gross));
      text('beGrossCell', money(b.gross));
      // a new due date: its term and disconnection date; the same due date: the bill's own
      ['beTerm', 'beDisc'].forEach(function (id) { var el = document.getElementById(id); if (el && stored[id]) el.innerHTML = stored[id].html; });
      if (b.due_date) {
        text('beTerm', b.term_days !== null ? b.term_days + ' days' : '—');
        if (b.disconnection_date) text('beDisc', dateText(b.disconnection_date));
      }
      if (b.schedule) {
        b.schedule.forEach(function (s, i) {
          var cell = document.querySelector('#beSchedule [data-i="' + i + '"]');
          if (cell) cell.innerHTML = money(s.amount) + (Math.abs(s.amount - s.old_amount) >= 0.005 ? '<span class="was">Was ' + money(s.old_amount) + '</span>' : '');
        });
      }
    }
  }
  function preview() {
    var my = ++seq;
    var fd = new FormData();
    fd.append('csrf_token', form.querySelector('[name=csrf_token]').value);
    fd.append('bill', billKey);
    inputs.forEach(function (i) { fd.append(i.name, i.value); });
    document.getElementById('beBill').classList.add('busy');
    fetch('../process/previewBillCorrection.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'The corrected bill could not be worked out (HTTP ' + r.status + ').' }; }); })
      .then(function (res) {
        if (my !== seq) return;            // a later change is being worked out
        document.getElementById('beBill').classList.remove('busy');
        var note = document.getElementById('beReprice');
        if (!res.ok) {
          previewOk = false;
          var bad = inputs.filter(differs)[0] || inputs[0];
          setError(res.error, bad);
          if (changed()) text('beNew', '—');
          refreshSave(oldTotal);
          return;
        }
        previewOk = true;
        setError('');
        if (!changed()) {
          // nothing changed: the bill stays as it was issued; say so when saving it again would price it otherwise
          restore();
          var again = Math.abs(res.bill.total - oldTotal) >= 0.005;
          note.hidden = !again;
          note.textContent = again ? 'Saved again as it is, this bill would come to ' + money(res.bill.total)
            + (isWater ? ' at the current tariff and discounts.' : ' at the bill code\'s current price.') : '';
          refreshSave(oldTotal);
          return;
        }
        note.hidden = true;
        apply(res.bill);
        refreshSave(res.bill.total);
      })
      .catch(function () {
        if (my !== seq) return;
        document.getElementById('beBill').classList.remove('busy');
        previewOk = false;
        setError('The corrected bill could not be worked out. Check the connection and try again.');
        refreshSave(oldTotal);
      });
  }
  function schedule() { mark(); clearTimeout(timer); timer = setTimeout(preview, 280); }
  inputs.forEach(function (i) { i.addEventListener('input', schedule); i.addEventListener('change', schedule); });
  form.addEventListener('submit', function (ev) {
    var reason = document.getElementById('beReason');
    if (save.disabled) { ev.preventDefault(); return; }
    if (reason && reason.value.trim().length < 5) {
      ev.preventDefault(); setError('Give the reason for the correction (at least 5 characters).', null); reason.focus();
    }
  });
  mark();
  preview();   // checks the bill can be corrected at today's set-up; the bill itself stays as issued until something is changed
})();
</script>
<?php endif; ?>
</body>
</html>
